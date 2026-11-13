# Ingress Architecture Specification

**Manuel S. Enverga University Foundation (MSEUF)**  
*LAN-Enabled Web & Mobile Event Ticketing, Admission, and Attendance Management System*

---

## 1. Deployment topology

Ingress runs as a cloud application and one or more separate Event Node applications. Each runtime has a separate PostgreSQL database and application key. The cloud handles accounts, event discovery, registration, ticket transfers, and organizer reporting. An Event Node receives one encrypted event snapshot and becomes the admission authority after the organizer confirms its staging and import receipts and activates the signed authority grant.

```text
Student website --HTTPS--> Cloud runtime --PostgreSQL
                           |
                           | encrypted snapshot and signed authority grant
                           v
Staff-only scanner app --venue LAN--> Event Node --PostgreSQL
                                   |
                                   | authenticated admission and walk-in sync
                                   v
                               Cloud runtime
```

The snapshot transfer is a manual operator workflow. After authority activation, the Event Node records admissions and walk ins locally. Staff scanners need the venue LAN and the Event Node, but admission does not require public internet. Students can display previously downloaded ticket PDFs offline; the Event Node still validates each credential.

Staff authenticate to the Event Node with local credentials or a one use pairing QR. The mobile app stores only the Event Node staff session. Standard preview and production scanner requests require HTTPS and use Android system CAs. The dedicated course-demo `lanDemo` profile permits HTTP only to a private IPv4 Node address on the isolated venue LAN; this exposes credentials and ticket data to other devices on that network. See [Staff scanner build instructions](../mobile/README.md).

The organizer attendance page reports current presence and movement counts and provides a private CSV export. Events can require staff to confirm an approved ID check. The system does not collect photos. See [deployment and operating limits](second-half-architecture.md) and the [deployment guide](deployment.md) for recovery, retention, and production configuration details.

---

## 2. Core entities and database schema

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

### Simulated paid checkout and reservations

Paid checkout is an internal demo path. It accepts no payment credentials and calls no financial provider. The deployment must explicitly set `INGRESS_SIMULATED_PAYMENTS_ENABLED=true`, and an authorized event manager must enable the event-level switch. The event switch and its actor are recorded in `event_simulated_payment_audits`. The deployment switch defaults to false and is independent of `APP_ENV` and `INGRESS_DEMO_MODE`.

`checkout_orders` snapshots the selected category price and currency and holds one seat for ten minutes. Capacity checks count confirmed registrations plus unexpired pending and failed orders at both event and category level. Checkout creation, payment confirmation, cancellation, expiry, and event capacity edits acquire the event row lock first. A failed attempt can be retried while the reservation is active. Cancellation, expiry, or event authorization revocation releases the seat and cancels any pending attempt.

`payment_attempts` records the selected generic test method and simulator outcome only. It has no fields for card, wallet, banking, or identity credentials. The server chooses the simulator result; the confirmation endpoint ignores client status fields. On success, one database transaction creates the registration and ticket and marks the order and attempt successful. Replaying confirmation returns the existing registration. Pending, failed, cancelled, and expired orders do not issue tickets. This workflow must remain isolated from any real financial integration.

For an accountless purchase whose successful confirmation response is interrupted, the order can restore the ticket and a one-time code handoff for fifteen minutes. The handoff is encrypted with the Laravel application key, hidden from model serialization, and never stored as plaintext. Viewing the ticket clears it; after expiry the application refuses recovery and clears it on the next checkout view. The code is never included in the PDF or QR.

### Keeping capacity valid when an organizer edits an event

Registration is not the only writer of capacity. `OrganizerEventController::update()` takes the **same `events` row lock** inside a transaction, counts confirmed registrations and active paid reservations, and rejects any new capacity below that count:

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

No lock cycle can form because any path that locks the `events` row acquires it **before** any ticket or registration row. The scanner takes `FOR SHARE` on `events` rather than `FOR UPDATE` deliberately: shared locks are mutually compatible, so scanners for *different* tickets run in parallel without serializing each other, while event writers (event cancellation, completion, or capacity edits taking `FOR UPDATE`) must wait until in-flight admissions commit.
### Ticket lifecycle and credential versioning

Ingress models an authoritative ticketing lifecycle rather than a temporary reservation system:

```text
Attendee registers
  │
  ▼
Ticket issued (active, credential_version = 1)
  │
  ├──► Admitted at venue gate (admissions row recorded)
  │
  ├──► Transferred (recipient accepts, then ownership and credential rotate atomically)
  │
  └──► Revoked (administrative invalidation by organizer/admin)
```

Attendees cannot self-cancel an issued ticket. Once registered, the ticket remains tied to the attendee. Repeated registration attempts for the same event are rejected and cannot rotate credentials, restore capacity, or reset admission history.

- Every admission records the `credential_version` under which the ticket was admitted for audit and idempotency scoping.
- Presence operates at the ticket level: its latest entry or exit determines current state, and credential rotation never resets movement history.
- Future controlled rotations, such as administrative reissue or ticket transfer, preserve audit history without allowing retired credentials or scan IDs to be replayed.

### Verifying concurrency on real PostgreSQL

SQLite serializes writers and cannot demonstrate real lock contention, so the behavioural suite (which runs on in-memory SQLite) proves logic but not races. The concurrency harness checks 38 scenarios against real PostgreSQL, including the listed invariants:

| Invariant | Contested resource | Expected outcome |
| :--- | :--- | :--- |
| Final-seat registration | `events` row (capacity 1) | exactly one confirmed registration |
| Same-ticket entry | `tickets` row | exactly one entry while outside; later entries are rejected until a valid exit and permitted re-entry |
| Concurrent exit and entry | `tickets` row | ticket locking preserves a valid sequence and applies the event re-entry policy |
| Replayed `client_scan_id` across two tickets | `admissions.client_scan_id` unique index | exactly one admission row; loser receives `idempotency_conflict`, never an unhandled 500 |
| Unique-violation mapping | `admissions.client_scan_id` unique index, deterministically | a worker held behind an uncommitted conflicting insert is mapped to `idempotency_conflict` |
| Event cancellation during an in-flight admission | `events` row, `FOR SHARE` vs `FOR UPDATE` | the scan blocks, then is rejected as `event_cancelled`; no admission recorded |
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

So the security of a stolen database rests on `APP_KEY` staying secret. It must live only in the server environment (`api/.env`), never in the repository, a backup that also contains the dump, or client code. Rotating `APP_KEY` invalidates every `encrypted_secret`, so treat it as a long-lived production secret.

The app needs to display a ticket again after login, so the server retains an encrypted QR payload that it can return only to the ticket owner. A per-ticket key derivation scheme could reduce the risk of a combined database and application-key leak, but the current implementation does not use one.

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
5. **Anti-Passback Pre-Check**: Checks if the ticket was already admitted under any credential version. If so, returns HTTP 409 `already_admitted`.
6. **Attendee Identity**: If valid, returns HTTP 200 with `attendee_name`, `student_id`, and `profile_photo_url` (optional / nullable, never encoded in the QR code).

**Preview creates ZERO database records, acquires zero locks, and leaves the ticket unconsumed.** If staff selects `NOT THIS PERSON` or the attendee walks away, no state changes occur.

### Phase 2: Authoritative Admission Transaction (`POST .../admissions`)
When staff taps `CONFIRM ADMISSION`, the client posts `{ access_point_id, qr_payload, client_scan_id }` (using a newly generated cryptographic UUID). The server executes the transactional admission pipeline:

1. **Authenticated User**: Confirms caller has a valid Sanctum bearer token.
2. **Event State & Staff Assignment**: Re-checks event state and staff assignment.
3. **Access Point**: Confirms the selected gate exists and matches staff assignment.
4. **QR Format**: Verifies regex `^ingress:v1:([A-Za-z0-9\-]+):([A-Fa-f0-9]{64})$`.
5. **Ticket Existence**: Looks up ticket by `public_id`.
6. **Secret Verification**: Verifies `hash_equals(ticket.qr_hash, sha256(secret))`.
7. **Registration Status**: Verifies attendee registration is `confirmed`.
8. **Ticket Revocation**: Verifies ticket status is `active` (not administratively revoked).
9. **Event Match**: Confirms ticket belongs to the currently selected event.
10. **Idempotency Check**: If an admission already exists with the same `client_scan_id`, the request is only treated as a retry when it also matches the same `ticket_id`, `credential_version`, `access_point_id`, and event. A mismatch returns HTTP 409 `idempotency_conflict`; a match returns the original admission response (HTTP 200). An insert that loses the race on the `UNIQUE(client_scan_id)` index is caught and mapped to the same 409 rather than surfacing as an unhandled database error.
11. **Anti-Passback Check (row-locked)**: Inside `DB::transaction()`, the **`events` row is locked `FOR SHARE`** and the event state (`cancelled` / `completed` / past `ends_at`) is revalidated, then the authoritative `tickets` row is locked with `lockForUpdate()` and the credential state (secret, ticket status, registration status, event) is re-validated under that lock. Idempotency is re-checked under the lock.
    - The shared event lock is what stops a concurrent event cancellation or completion from committing between the state check and the insert. Because `FOR SHARE` locks are mutually compatible, scanners for different tickets are not serialised behind each other (`FOR UPDATE` would have created exactly that bottleneck).
    - Concurrent scans of the same ticket are serialised by the `tickets` row lock: the first commits, and every later scanner observes the committed admission.
    - Anti-passback is ticket-level: any prior admission for the ticket across all entrances and credential versions triggers **`Already Checked In`**.
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
  - Laravel queries the central database under lock and immediately detects the existing admission.
  - Gate B scanner displays:
    ```
    Already Checked In
    Previously admitted at Gate A, 1:42 PM
    ```
- Result: Distributed double-entry attacks are strictly prevented.

---

## 7. Identity vs Authorization

The system keeps identity separate from event responsibility. The same person can be an attendee, manage one assigned event, and scan another event when explicitly assigned.

**Identity — who a person is at the university.** A student, an employee, an alumnus, or a guest. This is a long-lived fact about a person and is what an institution would eventually source from an authoritative directory (for example `myMSEUF` SSO).

**Authorization / responsibility — what a person may do in Ingress, and for which event.** An attendee, event staff, organizer, or admin. These are *event-scoped responsibilities*: the same person can be a student (identity) and event staff (responsibility) for one event while being an ordinary attendee for every other.

The Cloud authorization model uses three separate relationships:

- `events.organizer_id` remains the event owner. Existing owner IDs are preserved, and owner rights are limited to that event.
- `event_manager_assignments` grants management of one specific event. A platform administrator creates or revokes these rows. An `event_manager` role value without an active assignment grants no event access and does not grant event creation or global administration.
- `event_staff` grants scanner access for one specific event and optionally one gate. Event managers and students can be assigned as scanners without changing their account role. A manager is not implicitly allowed to scan; a separate scanner assignment is required.

`event_responsibility_audits` records manager assignment, invitation, acceptance, revocation, scanner assignment, gate change, and scanner revocation. Audit rows keep event, actor, subject, and gate text snapshots so the history remains readable after an account or event is removed. Removing a manager assignment immediately removes its event-management access. Scanner admission remains based on the existing assignment checks and is unchanged on the Event Node.

An administrator can assign an existing account directly or invite a new email address. New-account invitations use a random single-use token stored only as a SHA-256 hash, expire after 48 hours, and place the raw token in the email URL fragment. The invitation page removes the fragment before sending the token in a private POST body. Invitations require a configured safe mail transport; logging and plaintext SMTP are rejected before an invitation is created. Invitation responses are non-cacheable with a no-referrer policy. Firebase handles student verification and password-reset messages.

---

## 8. Authentication, Rate Limiting, and Demo Mode

**Mobile** authenticates with Sanctum bearer tokens issued by `/api/v1/auth/login` and stored on-device in hardware-backed secure storage (`expo-secure-store`). A token is only meaningful to the server that issued it, so changing the configured Event Node clears the stored token and cached identity before the new address is saved, and returns the user to the login screen.

Session restoration distinguishes two failure modes:

| `/me` result | Behaviour |
| :--- | :--- |
| `401` | The token is invalid for this server — stored token and cached identity are cleared. |
| Transport failure (`status: 0`) | The server is unreachable — the cached identity is kept so the app can show who is signed in, while every server action still fails closed. |

**Organizer web** uses ordinary Laravel session authentication. Platform administrators and legacy event organizers retain their existing access. A user with an active manager assignment or owned event can also sign in, but event policies check each event independently; the `event_manager` role alone is insufficient, and managers cannot create events. Students use Firebase for sign-in and can reach management pages only when their event-specific assignment permits it.

**Student web identity** is separate from organizer credentials and Event Node identities. The Cloud student page uses Firebase email/password authentication, Firebase-managed verification and password-reset emails, and browser-session persistence. Firebase passwords are sent directly to Firebase and are never stored by Laravel. Firebase's action links return to `/student/auth`; the Firebase authorized-domain list must include the deployed Cloud host.

After verification, the browser sends its Firebase ID token in a CSRF-protected POST to `/student/session`. Laravel checks that token with Firebase Identity Toolkit `accounts:lookup` using the configured Firebase project key, then independently enforces `emailVerified`, a valid email, an enabled password provider, and a student-only local account. Cloud Admin can require the exact `@student.mseuf.edu.ph` domain for new accounts and registrations. When the policy is enabled, personal-email accounts created before it was enabled can still sign in and view their existing tickets, but cannot make new student registrations. Student-only events and ticket categories always require an institutional address, regardless of the setting. Laravel does not accept a client-supplied UID, verification flag, or role. The local `firebase_uid` is unique and hidden from serialized user data. An unlinked local student can be linked only when the verified address matches; admin, organizer, staff, conflicting UID, and duplicate-email records are rejected. New Firebase students receive no self-reported student number, and their required local password field is set to an unknown random value rather than their Firebase password.

Student sessions rotate the Laravel session ID and require a verified Firebase-linked student for the student dashboard. The browser page, ID-token exchange, and dashboard are non-cacheable; the exchange is rate-limited and remains HTTPS-only outside local/testing. These Firebase routes are Cloud-only, so the Event Node has no Firebase runtime dependency. The emailed activation and Sanctum password login paths remain available on the web and legacy API. The native scanner does not use Cloud student authentication. A verified university mailbox proves mailbox ownership, not current enrollment; event-specific enrollment evidence still requires an authoritative source.

### Login rate limiting

Both login entry points share one named limiter, `throttle:login`, defined in `AppServiceProvider`:

- **5 attempts per minute**, keyed by lowercased **email + client IP**.
- **Every** attempt counts, including successful ones, because the middleware runs before the controller and the counter cannot be reset from inside the request.
- Keying on email+IP means one account under attack does not lock out other accounts behind the same campus NAT, and a shared IP cannot be used to spread guesses across accounts without each account being counted.
- API clients receive the JSON `429`; a throttled browser login is redirected back to the form with a readable message instead of a bare error page.

Throttle state uses the configured cache store (`database` in production, `array` in tests).

### Demo mode

`INGRESS_DEMO_MODE` → `config('app.demo_mode')` (default **false**) gates predictable server demo credentials. See ADR 010 for the decision; the seeder and organizer login hint are inert unless an environment opts in, and `APP_ENV=production` with demo mode on is a hard boot error. The current mobile scanner has no demo login or attendee credentials.
