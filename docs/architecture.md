# Ingress Architecture Specification

**Manuel S. Enverga University Foundation (MSEUF)**  
*LAN-Enabled Web & Mobile Event Ticketing, Admission, and Attendance Management System*

---

## 1. System Overview & Deployment Topology

Ingress is engineered as a local-first, server-authoritative event management architecture. For campus-scale physical events, multiple scanner devices and attendee phones must operate with high throughput and low latency, independent of public internet availability.

```
                           +----------------------------------------+
                           |          Ingress Event Server          |
                           |       (Laptop on Campus Wi-Fi)         |
                           |                                        |
                           |   +--------------------------------+   |
                           |   |     Laravel 13 API & Blade     |   |
                           |   |   - Organizer Admin Console    |   |
                           |   |   - Sanctum Token Auth         |   |
                           |   |   - 11-Step Admission Engine   |   |
                           |   +---------------+----------------+   |
                           |                   |                    |
                           |   +---------------v----------------+   |
                           |   |         PostgreSQL 18          |   |
                           |   |   - lockForUpdate() Capacity   |   |
                           |   |   - Atomic Anti-Passback       |   |
                           |   |   - Idempotency Constraints    |   |
                           |   +--------------------------------+   |
                           +-------------------+--------------------+
                                               |
                                    Campus Wi-Fi / Local Subnet
                                        (No Internet Needed)
                                               |
                  +----------------------------+----------------------------+
                  |                                                         |
        +---------v---------+                                     +---------v---------+
        |   Attendee Phone  |                                     |    Staff Phone    |
        | Expo React Native |                                     | Expo React Native |
        |                   |                                     |                   |
        | - Discover Events |                                     | - Select Gate     |
        | - Register (Safe) |                                     | - Scan QR Ticket  |
        | - Render SVG QR   |                                     | - Instant Feedback|
        +-------------------+                                     +-------------------+
```

### Server Role (Authoritative Node)
- **Host**: Laptop connected to local campus router / AP (bound to `0.0.0.0:8000`).
- **Backend**: Laravel 13 on PHP 8.4 (Laravel Herd / CLI runtime).
- **Database**: PostgreSQL 18 running on `127.0.0.1:5433` (isolated local cluster).
- **Organizer Interface**: Server-rendered Laravel Blade web templates.
- **Client Interface**: RESTful JSON API authenticated via Laravel Sanctum bearer tokens.

### Peripheral Role (Zero-Trust Scanners & Attendees)
- **Framework**: Expo SDK 57 (React Native 0.86).
- **Scanner Philosophy**: Scanners are **untrusted capture peripherals**. A scanner never determines ticket validity autonomously. It captures the optical payload, generates a unique UUID `client_scan_id`, and dispatches it to the server. If the server is unreachable, the scanner **fails closed** (`SERVER UNAVAILABLE`).

---

## 2. Core Entities & Database Schema

```
 +--------------------+       +--------------------+       +--------------------+
 |       users        |       |       venues       |       |       events       |
 +--------------------+       +--------------------+       +--------------------+
 | id                 |       | id                 |       | id                 |
 | name               |       | name               |       | organizer_id (FK)  |
 | email (UQ)         |       | code (UQ)          |       | venue_id (FK)      |
 | student_id (UQ)    |       | location           |       | title              |
 | role               |       | default_capacity   |       | slug (UQ)          |
 | password           |       +---------+----------+       | capacity           |
 +---------+----------+                 |                  | registration_open  |
           |                            |                  | registration_close |
           | 1                          | 1                | starts_at / ends_at|
           |                            |                  | status             |
           |                            |                  +----+----------+----+
           |                            +-----------+           |          |
           |                                        | *         | 1        | 1
           |        +-------------------------------+-----------+          |
           |        |                               |                      |
           |        | *                             | *                    | *
 +---------v--------v-+                   +---------v----------+  +--------v-----------+
 |   registrations    |                   |   access_points    |  |    event_staff     |
 +--------------------+                   +--------------------+  +--------------------+
 | id                 |                   | id                 |  | id                 |
 | event_id (FK)      |                   | event_id (FK)      |  | event_id (FK)      |
 | user_id (FK)       |                   | name               |  | user_id (FK)       |
 | status             |                   | code               |  | access_point_id(FK)|
 | cancelled_at       |                   | is_active          |  +--------------------+
 +---------+----------+                   +---------+----------+  | UQ(event, user)    |
 | UQ(event, user)    |                             |             +--------------------+
 +---------+----------+                             |
           | 1                                      |
           |                                        |
           | 1                                      |
 +---------v----------+                             |
 |      tickets       |                             |
 +--------------------+                             |
 | id                 |                             |
 | registration_id(FK)|                             |
 | public_id (UQ)     |                             |
 | qr_hash (IDX)      |                             |
 | encrypted_secret   |                             |
 | status             |                             |
 | issued_at          |                             |
 +---------+----------+                             |
           | 1                                      |
           |                                        |
           | *                                      | *
 +---------v----------------------------------------v-+
 |                     admissions                     |
 +----------------------------------------------------+
 | id                                                 |
 | ticket_id (FK)                                     |
 | access_point_id (FK)                               |
 | scanned_by_user_id (FK)                            |
 | client_scan_id (UQ)                                |
 | admitted_at                                        |
 +----------------------------------------------------+
```

### Constraints & Indexes
1. `UNIQUE(event_id, user_id)` on `registrations`: Prevents duplicate registrations.
2. `UNIQUE(registration_id)` on `tickets`: Exactly one ticket per confirmed registration.
3. `UNIQUE(public_id)` on `tickets`: Opaque ticket reference identifier.
4. `UNIQUE(client_scan_id)` on `admissions`: Guaranteed idempotency for scanner network retries.
5. `INDEX(qr_hash)` on `tickets`: Fast constant-time lookup for scanned QR tokens.

---

## 3. Concurrency-Safe Registration Engine

To enforce the invariant:
$$\text{confirmed registrations} \le \text{event capacity}$$

Ingress employs **pessimistic row-level locking** (`SELECT ... FOR UPDATE`):

```php
DB::transaction(function () use ($user, $eventId) {
    // 1. Acquire exclusive write lock on event row
    $event = Event::where('id', $eventId)->lockForUpdate()->first();

    // 2. Validate event status & registration window
    if (! $event->isPublished() || ! $event->isRegistrationOpen()) {
        throw new ValidationException('Registration closed.');
    }

    // 3. Check duplicate registration
    if (Registration::where('event_id', $event->id)->where('user_id', $user->id)->exists()) {
        throw new ValidationException('Already registered.');
    }

    // 4. Check capacity invariant under row lock
    $confirmed = Registration::where('event_id', $event->id)
        ->where('status', 'confirmed')
        ->count();

    if ($confirmed >= $event->capacity) {
        throw new ValidationException('Event capacity reached.');
    }

    // 5. Reserve seat & issue ticket atomically
    $registration = Registration::create([...]);
    $ticket = Ticket::create([...]);
});
```

Under high concurrent load (e.g. 50 students competing for the final seat), database transactions queue cleanly behind the row lock. Exactly one transaction succeeds; all subsequent transactions see `confirmed == capacity` and fail safely without overbooking.

### Keeping capacity valid when an organizer edits an event

Registration is not the only writer of capacity. `OrganizerEventController::update()` takes the **same `events` row lock** inside a transaction, counts the confirmed registrations, and rejects any new capacity below that count:

```
capacity = 500, confirmed = 420, requested = 300  ->  rejected (422, capacity unchanged)
capacity = 500, confirmed = 420, requested = 420  ->  accepted
```

Because both paths lock the same row first, the count cannot change between the check and the write.

### Lock ordering

Locks are ordered to guarantee that no deadlock cycle can form:

| Path | Order |
| :--- | :--- |
| Organizer creates a registration | `events` FOR UPDATE → `registrations`/`tickets` |
| Organizer edits an event | `events` FOR UPDATE → `registrations` (count) |
| Scanner admits a ticket | `events` FOR SHARE → `tickets` FOR UPDATE |
| Attendee cancels registration | `tickets` FOR UPDATE → `registrations` |

No lock cycle can form because any path that locks the `events` row acquires it **before** any ticket or registration row, while `RegistrationController::destroy()` locks only `tickets` and never requests an `events` lock while holding the ticket lock. The scanner takes `FOR SHARE` on `events` rather than `FOR UPDATE` deliberately: shared locks are mutually compatible, so scanners for *different* tickets run in parallel without serializing each other, while event writers (cancel, complete, or capacity edits taking `FOR UPDATE`) must wait until the in-flight admission commits.
### Re-registration after cancellation

`registrations` carries `UNIQUE(event_id, user_id)`, so a cancelled registration cannot simply be replaced by a new row. When a previously cancelled attendee registers again, the existing row is reactivated (`status = confirmed`, `cancelled_at = null`) and the ticket credential is rotated — a new `public_id`, a new 256-bit secret, a new `qr_hash`, `revoked_at = null`, and `credential_version` incremented.

Two consequences follow:

- The previously revoked QR fails constant-time verification, while the newly issued QR validates normally.
- Because the rotated credential reuses the same `tickets` row, the admission history recorded under the retired credential would otherwise still satisfy the anti-passback check. Each admission therefore snapshots the `credential_version` that admitted it, and anti-passback only considers admissions matching the ticket's **current** `credential_version`. Audit history is preserved in the `admissions` table, but a credential issued after a cancellation is immediately admittable.

### Verifying concurrency on real PostgreSQL

SQLite serializes writers and cannot demonstrate real lock contention, so the behavioural suite (which runs on in-memory SQLite) proves logic but not races. Six invariants are verified separately against real PostgreSQL:

| Invariant | Contested resource | Expected outcome |
| :--- | :--- | :--- |
| Final-seat registration | `events` row (capacity 1) | exactly one confirmed registration |
| Same-ticket admission | `tickets` row | exactly one admission row; loser receives `already_admitted` |
| Replayed `client_scan_id` across two tickets | `admissions.client_scan_id` unique index | exactly one admission row; loser receives `idempotency_conflict`, never an unhandled 500 |
| Unique-violation mapping | `admissions.client_scan_id` unique index, deterministically | a worker held behind an uncommitted conflicting insert is mapped to `idempotency_conflict` |
| Cancellation during an in-flight admission | `events` row, `FOR SHARE` vs `FOR UPDATE` | the scan blocks, then is rejected as `event_cancelled`; no admission recorded |
| Different tickets scanned concurrently | `events` row, `FOR SHARE` held by another session | both scans are admitted while the shared lock is held — scanners are not serialised |

```bash
cd api
php scripts/verify_pgsql_concurrency.php
```

The verifier provisions a dedicated `ingress_concurrency` database, spawns two Laravel child processes per case, signals `READY` only after each child has completed its PostgreSQL handshake, and releases both through a `GO` barrier so their transactions genuinely overlap before asserting the outcomes above.

The fourth case exists because the third case's branch depends on OS scheduling — the loser can be decided either by the idempotency pre-check or by the unique index. The mapping case holds an uncommitted conflicting insert on a second connection, polls `pg_stat_activity` until the worker is provably blocked on the unique index, then commits, so the mapped path is always exercised. Each worker reports whether it reached the admission insert (observed via `DB::beforeExecuting`, because `DB::listen` only fires for statements that return). It exits non-zero on failure and never touches demonstration data.

---

## 4. Opaque QR Credential Design

### Payload Structure
```
ingress:v1:<ticket_public_id>:<random_secret>
```

- `ingress:v1`: Protocol and schema version identifier.
- `<ticket_public_id>`: 14-character alphanumeric string (e.g. `ING-AB12CD34EF`).
- `<random_secret>`: 256-bit cryptographically secure pseudorandom token (`bin2hex(random_bytes(32))`, 64 hex characters).

### Security Properties
1. **Zero PII**: No student names, emails, student numbers, or course details are encoded in the QR code.
2. **Two stored views of the secret**, with different properties:
   - `qr_hash = hash('sha256', $secret)` is **one-way** and is the only value used to verify a scan.
   - `encrypted_secret = Crypt::encryptString($secret)` is **recoverable by the application**: it is encrypted with the framework key (`APP_KEY`) so the attendee can redisplay their own QR after reinstalling the app or switching devices. Without it, a lost device would permanently lose the ticket.
3. **Constant-Time Verification**: Verification uses PHP's `hash_equals($ticket->qr_hash, hash('sha256', $scannedSecret))` to prevent timing attacks.

### Threat model for stored ticket secrets

| Compromise | Consequence |
| :--- | :--- |
| Database dump only (`qr_hash`, `encrypted_secret` leaked) | The plaintext secret is **not** directly readable. `qr_hash` is one-way, and `encrypted_secret` is unreadable without `APP_KEY`. |
| Database dump **and** `APP_KEY` | `encrypted_secret` can be decrypted, so the plaintext QR secret is recoverable and a working QR can be reconstructed. |

So the security of a stolen database rests on `APP_KEY` staying secret. It must live only in the server environment (`api/.env`), never in the repository, a backup that also contains the dump, or client code. Rotating `APP_KEY` invalidates every `encrypted_secret` (attendees would have to re-register), so treat it as a long-lived production secret.

This is a deliberate trade-off for the 50% milestone: one-way-only storage would be marginally safer against a combined leak, but would make the ticket unrecoverable for the attendee. A later milestone may move to per-ticket key derivation if the stronger property is required.

---

## 5. Two-Phase Server-Authoritative Admission Workflow

To prevent consuming tickets before verifying the attendee's physical identity (e.g. school ID card check), Ingress separates scanning into two distinct phases:

> **Fundamental Invariant**: Ticket validation does not create an admission. Admission occurs only after authorized entrance staff confirms the attendee.

```
[Staff Phone]                     [Ingress Event Node (Laravel)]
      │                                         │
      │ 1. Scan QR                              │
      ├────────────────────────────────────────>│ POST /admissions/preview
      │                                         │ (Read-only validation)
      │                                         │
      │ 2. Return Attendee Identity             │
      │<────────────────────────────────────────┤ 200 OK { ticket: { attendee_name, student_id } }
      │                                         │ (0 Admissions created)
      │                                         │
      │ 3. Staff Verifies University ID         │
      │    [ NOT THIS PERSON ] -> Cancel        │
      │    [ CONFIRM ADMISSION ]                │
      │                                         │
      │ 4. Confirm Admission                    │
      ├────────────────────────────────────────>│ POST /admissions
      │                                         │ (Atomic transaction under lock)
      │                                         │
      │ 5. Admission Recorded                   │
      │<────────────────────────────────────────┤ 200 OK { status: 'admitted' }
```

### Phase 1: Read-Only Ticket Preview (`POST .../admissions/preview`)
When a staff member scans a ticket, the mobile client posts `{ access_point_id, qr_payload }` to the preview endpoint. The server executes non-mutating validation checks:
1. **Authenticated Staff**: Confirms caller has a valid Sanctum bearer token and event assignment.
2. **Event & Gate State**: Rejects cancelled/ended events and inactive or unassigned gates.
3. **Cryptographic Integrity**: Constant-time verification of `hash_equals(ticket.qr_hash, sha256(secret))`.
4. **Registration Status**: Verifies attendee registration is confirmed and ticket is active (not revoked).
5. **Anti-Passback Pre-Check**: Checks if the current credential version was already admitted. If so, returns HTTP 409 `already_admitted`.
6. **Attendee Identity**: If valid, returns HTTP 200 with `attendee_name`, `student_id`, and `profile_photo_url` (optional / nullable, never encoded in the QR code).

**Preview creates ZERO database records, acquires zero locks, and leaves the ticket unconsumed.** If staff selects `NOT THIS PERSON` or the attendee walks away, no state changes occur.

### Phase 2: Authoritative Admission Transaction (`POST .../admissions`)
When staff taps `CONFIRM ADMISSION`, the client posts `{ access_point_id, qr_payload, client_scan_id }` (using a newly generated cryptographic UUID). The server executes the 11-step transactional admission pipeline:

1. **Authenticated User**: Confirms caller has a valid Sanctum bearer token.
2. **Event State & Staff Assignment**: Re-checks event state and staff assignment.
3. **Access Point**: Confirms the selected gate exists and matches staff assignment.
4. **QR Format**: Verifies regex `^ingress:v1:([A-Za-z0-9\-]+):([A-Fa-f0-9]{64})$`.
5. **Ticket Existence**: Looks up ticket by `public_id`.
6. **Secret Verification**: Verifies `hash_equals(ticket.qr_hash, sha256(secret))`.
7. **Registration Status**: Verifies attendee registration is `confirmed`.
8. **Ticket Revocation**: Verifies ticket status is `active` (not revoked or cancelled).
9. **Event Match**: Confirms ticket belongs to the currently selected event.
10. **Idempotency Check**: If an admission already exists with the same `client_scan_id`, the request is only treated as a retry when it also matches the same `ticket_id`, `credential_version`, `access_point_id`, and event. A mismatch returns HTTP 409 `idempotency_conflict`; a match returns the original admission response (HTTP 200). An insert that loses the race on the `UNIQUE(client_scan_id)` index is caught and mapped to the same 409 rather than surfacing as an unhandled database error.
11. **Anti-Passback Check (row-locked)**: Inside `DB::transaction()`, the **`events` row is locked `FOR SHARE`** and the event state (`cancelled` / `completed` / past `ends_at`) is revalidated, then the authoritative `tickets` row is locked with `lockForUpdate()` and the credential state (secret, ticket status, registration status, event) is re-validated under that lock. Idempotency is re-checked under the lock.
    - The shared event lock is what stops a concurrent cancellation or completion from committing between the state check and the insert. Because `FOR SHARE` locks are mutually compatible, scanners for different tickets are not serialised behind each other (`FOR UPDATE` would have created exactly that bottleneck).
    - Concurrent scans of the same ticket are serialised by the `tickets` row lock: the first commits, and every later scanner observes the committed admission.
    - Passback only considers admissions whose `credential_version` matches the ticket's current one.
    - If such an admission exists: returns **`Already Checked In`** (HTTP 409 Conflict) with the previous gate name and timestamp.
    - If none exists: creates the `Admission` row and returns **`Ticket Valid`** (HTTP 200).

> No `UNIQUE(ticket_id)` constraint is used for anti-passback. Admission state is serialised in application transactional logic so that later milestones can record multiple admission events per ticket (ENTRY, EXIT, RE-ENTRY).
---

## 6. Anti-Passback & Multi-Gate Shared Source of Truth

In physical campus events with multiple entrance turnstiles (e.g. Gate A and Gate B at the MSEUF Gymnasium):
- Both Gate A and Gate B scanners connect to the central Ingress server over the campus LAN.
- When Gate A admits a ticket at 1:42 PM, the `admissions` table is updated atomically.
- If an attendee immediately sends a screenshot of their QR ticket to a friend at Gate B:
  - Gate B scanner captures the QR and posts to Laravel.
  - Laravel step 11 queries the central database and immediately detects the existing admission.
  - Gate B scanner displays:
    ```
    Already Checked In
    Previously admitted at Gate A, 1:42 PM
    ```
- Result: Distributed double-entry attacks are strictly prevented.

---

## 7. Identity vs Authorization

The system has two distinct concepts that are easy to conflate, and the schema keeps them separate on purpose.

**Identity — who a person is at the university.** A student, an employee, an alumnus, or a guest. This is a long-lived fact about a person and is what an institution would eventually source from an authoritative directory (for example `myMSEUF` SSO).

**Authorization / responsibility — what a person may do in Ingress, and for which event.** An attendee, event staff, organizer, or admin. These are *event-scoped responsibilities*: the same person can be a student (identity) and event staff (responsibility) for one event while being an ordinary attendee for every other.

The current implementation reflects this deliberately:

- `users.role` is a **coarse portal flag** used for routing and portal-level access (which app area you land in, whether the organizer console is reachable). Its constants (`admin`, `organizer`, `staff`, `student`) are a short-term convenience, not the responsibility model.
- The **responsibility model lives in `event_staff`**: a per-event assignment row with an optional specific gate. `AdmitTicket` authorizes a scan from `isAdmin() || event.organizer_id === user.id || an event_staff row exists` and never from `users.role` alone. A student who is assigned as staff for one event can therefore scan that event without gaining any other staff capability.

### Known conflation, deferred to the second half

Two places still read `users.role` where they should read a responsibility:

1. `mobile/app/index.tsx` routes a signed-in user to the staff scanner when `role === 'staff'`. A student who is also event staff lands in attendee mode and must switch manually.
2. `OrganizerEventController` offers only users whose `role` is `staff` in the staff picker, so a student cannot be assigned as event staff through the UI.

Neither is a security gap — the *authorization* decision is already assignment-based — but both are functional limitations. Resolving them means splitting identity from responsibility properly (separate tables or a many-to-many), which is a second-half change: this milestone intentionally does not redesign authentication or build a general RBAC system.

---

## 8. Authentication, Rate Limiting, and Demo Mode

**Mobile** authenticates with Sanctum bearer tokens issued by `/api/v1/auth/login` and stored on-device in hardware-backed secure storage (`expo-secure-store`). A token is only meaningful to the server that issued it, so changing the configured Event Node clears the stored token and cached identity before the new address is saved, and returns the user to the login screen.

Session restoration distinguishes two failure modes:

| `/me` result | Behaviour |
| :--- | :--- |
| `401` | The token is invalid for this server — stored token and cached identity are cleared. |
| Transport failure (`status: 0`) | The server is unreachable — the cached identity is kept so the app can show who is signed in, while every server action still fails closed. |

**Organizer web** uses ordinary Laravel session authentication. Only users who pass `isOrganizer()` may enter the organizer console; a student who authenticates successfully is logged straight back out with an explanatory error.

### Login rate limiting

Both login entry points share one named limiter, `throttle:login`, defined in `AppServiceProvider`:

- **5 attempts per minute**, keyed by lowercased **email + client IP**.
- **Every** attempt counts, including successful ones, because the middleware runs before the controller and the counter cannot be reset from inside the request.
- Keying on email+IP means one account under attack does not lock out other accounts behind the same campus NAT, and a shared IP cannot be used to spread guesses across accounts without each account being counted.
- API clients receive the JSON `429`; a throttled browser login is redirected back to the form with a readable message instead of a bare error page.

Throttle state uses the configured cache store (`database` in production, `array` in tests).

### Demo mode

`INGRESS_DEMO_MODE` → `config('app.demo_mode')` (default **false**) gates every predictable credential in the system. See ADR 010 for the decision; the operational effect is that the seeder, the organizer login hint, and the mobile quick-select are all inert unless an environment opts in, and `APP_ENV=production` with demo mode on is a hard boot error.
