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
                           |   |     Laravel 12 API & Blade     |   |
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
- **Backend**: Laravel 12 on PHP 8.4 (Laravel Herd / CLI runtime).
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
2. **One-Way Storage**: The database stores `qr_hash = hash('sha256', $secret)`. Even if the database is inspected, the raw secret cannot be reversed.
3. **Constant-Time Verification**: Verification uses PHP's `hash_equals($ticket->qr_hash, hash('sha256', $scannedSecret))` to prevent timing attacks.

---

## 5. 11-Step Server-Authoritative Admission Pipeline

When a staff member scans a ticket, the mobile app posts `{ access_point_id, qr_payload, client_scan_id }` to `/api/v1/staff/events/{event}/admissions`. The backend executes 11 sequential validation steps:

1. **Authenticated User**: Confirms caller has a valid Sanctum bearer token.
2. **Staff Assignment**: Confirms caller is explicitly assigned to this event in `event_staff` (or is event organizer/admin).
3. **Access Point**: Confirms selected gate exists, belongs to this event, and is active.
4. **QR Format**: Verifies regex `^ingress:v1:([A-Za-z0-9\-]+):([A-Fa-f0-9]{64})$`.
5. **Ticket Existence**: Looks up ticket by `public_id`.
6. **Secret Verification**: Verifies `hash_equals(ticket.qr_hash, sha256(secret))`.
7. **Registration Status**: Verifies attendee registration is `confirmed`.
8. **Ticket Revocation**: Verifies ticket status is `active` (not revoked or cancelled).
9. **Event Match**: Confirms ticket belongs to the currently selected event.
10. **Idempotency Check**: If an admission already exists with the same `client_scan_id`, returns the original admission response (HTTP 200) without duplicating records.
11. **Anti-Passback Check**: In an atomic transaction, checks if an admission already exists for `ticket_id`.
    - If found: returns **`Already Checked In`** (HTTP 409 Conflict) with the previous gate name and timestamp.
    - If not found: creates `Admission` row and returns **`Ticket Valid`** (HTTP 200).

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
    ALREADY CHECKED IN
    Previously admitted at Gate A, 1:42 PM
    ```
- Result: Distributed double-entry attacks are strictly prevented.
