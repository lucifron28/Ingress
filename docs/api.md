# Ingress REST API Documentation (v1)

**Base URL**: `https://<LAN_SERVER_HOST>/api/v1` for production Event Nodes

**Authentication**: Bearer Token via HTTP Header (`Authorization: Bearer <token>`)  
**Standard Headers**:
- `Accept: application/json`
- `Content-Type: application/json`

---

## 1. Authentication Endpoints

### Student account verification

`POST /auth/student-verification` is available only on the cloud runtime. Submit `{"email":"student@student.mseuf.edu.ph"}`. An eligible request returns `202` with the same generic message whether or not an account already exists. The endpoint accepts only `@student.mseuf.edu.ph`, limits requests by email and IP, and can return `422`, `429`, or `503` for invalid input, throttling, or unsafe production configuration.

The email contains a 30-minute, single-use link. Opening it displays a form; the account is created only when the form is submitted with a name, a self-reported student number, and a confirmed password of at least 12 characters and at most 72 bytes. The server assigns the student role and records email verification. Requesting another link invalidates the previous one. Email control does not verify the student number or enrollment. The URL fragment contains a bearer secret; do not forward or log it.

### Event Node staff login

`POST /auth/node-staff/login` is available only on an Event Node. Send `email`, the node-issued `password`, and an optional `device_name`. The node checks the local credential and a current imported staff assignment, then returns a bearer token with the `node:staff` ability and a 12-hour expiration. It returns a generic credential error for an invalid password or assignment and rate-limits attempts. HTTPS is required outside local and testing environments. The response has `Cache-Control: no-store`.

Node tokens support `GET /me`, `POST /auth/logout`, and the `/staff` event, gate, preview, and admission endpoints. Each node scan requires an activated signed grant, a current event assignment, the assigned gate, and a valid node token. Preview is read-only; only confirmation records an admission. A missing or invalid authority returns `403` with status `node_authority_unavailable`. Admissions are sent to the cloud in retryable batches when connectivity returns; cloud reports may lag. The ordinary `/auth/login` endpoint remains available on cloud and legacy runtimes, not nodes. The staff member's account role is not changed by a node assignment.

### Event Node scanner pairing

`POST /auth/node-staff/pair` is available only on an Event Node over HTTPS outside local and testing environments. It accepts `node_id`, a 64-character one-use `token`, and an optional `device_name`. The operator issues the token for one assigned staff member through the local pairing command. The node checks the target identity, three-minute expiry, current assignment, and local credential. Successful redemption consumes the token and returns a 12-hour `node:staff` session with `node_id`. Invalid, expired, already used, or revoked codes return a generic validation error. Requests are rate-limited by IP.

### 1.1 Mobile Login
Issues a personal access token for mobile attendees and event staff.

- **Method**: `POST`
- **Endpoint**: `/auth/login`
- **Access**: Public on cloud and legacy runtimes

#### Request Body
```json
{
  "email": "a23-35524@student.mseuf.edu.ph",
  "password": "password",
  "device_name": "Pixel-7-Staff"
}
```

#### Success Response (`200 OK`)
```json
{
  "token": "1|g8G2k3bX...",
  "token_type": "Bearer",
  "user": {
    "id": 7,
    "name": "Ron Vincent Cada",
    "email": "a23-35524@student.mseuf.edu.ph",
    "role": "student",
    "student_id": "A23-35524",
    "created_at": "2026-10-01T02:00:00.000000Z"
  }
}
```

#### Error Response (`422 Unprocessable Content`)
```json
{
  "message": "Invalid credentials.",
  "errors": {
    "email": ["Invalid credentials."]
  }
}
```

#### Error Response: throttled (`429 Too Many Requests`)

Login accepts 5 attempts per minute per email + client IP. Every attempt counts, including successful ones.

```json
{
  "message": "Too Many Attempts."
}
```

---

### 1.2 Current User Profile
Retrieves the profile of the authenticated bearer token holder.

- **Method**: `GET`
- **Endpoint**: `/me`
- **Access**: Authenticated (`auth:sanctum`)

#### Success Response (`200 OK`)
```json
{
  "user": {
    "id": 4,
    "name": "Staff Alex Rivera",
    "email": "staff.gate_a@mseuf.edu.ph",
    "role": "staff",
    "student_id": null,
    "created_at": "2026-10-01T02:00:00.000000Z"
  }
}
```

---

### 1.3 Mobile Logout
Revokes the current personal access token.

- **Method**: `POST`
- **Endpoint**: `/auth/logout`
- **Access**: Authenticated (`auth:sanctum`)

#### Success Response (`200 OK`)
```json
{
  "message": "Logged out successfully."
}
```

---

## 2. Event Discovery Endpoints

### 2.1 List Published Events
Returns upcoming published campus events available for discovery and registration.

- **Method**: `GET`
- **Endpoint**: `/events`
- **Access**: Public

#### Success Response (`200 OK`)
```json
{
  "data": [
    {
      "id": 1,
      "title": "MSEUF University Intramurals 2026",
      "slug": "mseuf-university-intramurals-2026",
      "description": "The annual university-wide sports, parade, e-sports, and athletic competition.",
      "venue": {
        "id": 1,
        "name": "MSEUF University Gymnasium",
        "code": "GYM",
        "location": "University Athletic Complex, Lucena City",
        "default_capacity": 1500
      },
      "capacity": 50,
      "confirmed_registrations_count": 12,
      "remaining_capacity": 38,
      "is_registration_open": true,
      "registration_opens_at": "2026-09-30T00:00:00.000000Z",
      "registration_closes_at": "2026-10-08T00:00:00.000000Z",
      "registration_closed_at": null,
      "starts_at": "2026-10-03T09:00:00.000000Z",
      "ends_at": "2026-10-03T17:00:00.000000Z",
      "status": "published",
      "is_user_registered": false,
      "user_ticket": null
    }
  ]
}
```

---

### 2.2 Event Details
Retrieves single event details including user registration status.

- **Method**: `GET`
- **Endpoint**: `/events/{id}`
- **Access**: Public (Published events) / Organizer (Draft events)

#### Success Response (`200 OK`)
```json
{
  "event": {
    "id": 1,
    "title": "MSEUF University Intramurals 2026",
    "capacity": 50,
    "remaining_capacity": 38,
    "is_registration_open": true,
    "is_user_registered": true,
    "user_ticket": {
      "id": 1,
      "public_id": "ING-A92B48EF12",
      "status": "active"
    }
  }
}
```

---

## 3. Registration & Ticket Endpoints

### 3.1 Register for Event
Transactionally reserves a seat with a row lock and issues a digital ticket. Registration is rejected after the scheduled deadline, after the event ends, or after an organizer closes registration. `registration_closed_at` is the organizer-initiated cutoff timestamp and is null when no manual cutoff has been recorded. A manual cutoff does not revoke existing tickets or transfer admission authority.

- **Method**: `POST`
- **Endpoint**: `/events/{id}/registration`
- **Access**: Authenticated (`auth:sanctum`)

#### Success Response (`201 Created`)
```json
{
  "message": "Registration confirmed and ticket issued.",
  "registration_id": 1,
  "ticket": {
    "id": 1,
    "public_id": "ING-A92B48EF12",
    "status": "active",
    "issued_at": "2026-10-01T02:15:00.000000Z"
  },
  "qr_payload": "ingress:v1:ING-A92B48EF12:6b86b273ff34fce19d6b804eff5a3f5747ada4eaa22f1d49c01e52ddb7875b4b"
}
```

#### Error: Duplicate Registration (`422 Unprocessable Content`)
```json
{
  "message": "You are already registered for this event.",
  "errors": {
    "registration": ["You are already registered for this event."]
  }
}
```

#### Error: Capacity Reached (`422 Unprocessable Content`)
```json
{
  "message": "Event capacity has been reached. No seats available.",
  "errors": {
    "capacity": ["Event capacity has been reached. No seats available."]
  }
}
```

---

### 3.2 My Tickets (Ticket Wallet)
Returns all tickets issued to the authenticated student. The response includes `qr_payload` only for an active ticket with a confirmed registration whose event has not been cancelled. Each ticket includes the event's current `status` and effective `ticket_transfers_allowed` policy; the policy is false at or after `ends_at` and after snapshot preparation.

- **Method**: `GET`
- **Endpoint**: `/me/tickets`
- **Access**: Authenticated (`auth:sanctum`)

#### Success Response (`200 OK`)
```json
{
  "data": [
    {
      "id": 1,
      "public_id": "ING-A92B48EF12",
      "status": "active",
      "issued_at": "2026-10-01T02:15:00.000000Z",
      "qr_payload": "ingress:v1:ING-A92B48EF12:6b86b...",
      "event": {
        "id": 1,
        "title": "MSEUF University Intramurals 2026",
        "status": "published",
        "venue": {
          "id": 1,
          "name": "MSEUF University Gymnasium",
          "location": "Lucena City"
        },
        "starts_at": "2026-10-03T09:00:00.000000Z",
        "ends_at": "2026-10-03T17:00:00.000000Z"
      },
      "attendee": {
        "name": "Ron Vincent Cada",
        "student_id": "A23-35524",
        "email": "a23-35524@student.mseuf.edu.ph"
      }
    }
  ]
}
```


### 3.3 Ticket Details
Retrieves single ticket details. The reusable `qr_payload` is returned **only to the attendee who owns an active ticket with a confirmed registration for an event that has not been cancelled**. The event includes its current `status` and effective `ticket_transfers_allowed` policy.

- **Method**: `GET`
- **Endpoint**: `/tickets/{id}`
- **Access**:
  - **Attendee owner** — full ticket, including `qr_payload`.
  - **Event organizer** (organizer of the ticket's event) — ticket metadata only; `qr_payload` is omitted.
  - **Admin** — ticket metadata only; `qr_payload` is omitted.
  - **Event staff** — `403 Forbidden`. Staff obtain verified attendee information through the admission endpoint instead, never by reading ticket secrets.

`encrypted_secret` is never serialised on any endpoint.

#### Attendee Owner Response (`200 OK`)
```json
{
  "ticket": {
    "id": 1,
    "public_id": "ING-A92B48EF12",
    "status": "active",
    "issued_at": "2026-10-01T02:15:00.000000Z",
    "qr_payload": "ingress:v1:ING-A92B48EF12:6b86b273ff34fce19d6b804eff5a3f5747ada4eaa22f1d49c01e52ddb7875b4b",
    "event": {
      "id": 1,
      "title": "MSEUF University Intramurals 2026",
      "status": "published",
      "venue": { "id": 1, "name": "MSEUF University Gymnasium", "location": "Lucena City" },
      "starts_at": "2026-10-03T09:00:00.000000Z",
      "ends_at": "2026-10-03T17:00:00.000000Z"
    },
    "attendee": {
      "name": "Ron Vincent Cada",
      "student_id": "A23-35524",
      "email": "a23-35524@student.mseuf.edu.ph"
    }
  }
}
```

#### Organizer / Admin Response (`200 OK`)
Identical shape, but the `qr_payload` key is **absent**.

#### Staff Response (`403 Forbidden`)
```json
{
  "message": "Unauthorized access to ticket details."
}
```

### 3.4 Ticket Transfers

Transfers are available only on the legacy or cloud runtime. The event must be published, have transfers enabled, be before `ends_at`, and have no prepared admission snapshot. A new request or acceptance is rejected at or after the event end. Closing registration alone does not close transfers. The sender and recipient must have student accounts with verified institutional email addresses. This verifies email control, not enrollment. The recipient cannot already hold a registration for the event, including an accountless guest registration with the same email.

`POST /tickets/{id}/transfers` accepts `recipient`, which can be the recipient's verified institutional email address or student number. It creates a pending request; the current owner retains the ticket and QR until acceptance. `GET /me/ticket-transfers` returns pending requests for the authenticated sender and recipient. The recipient uses `POST /ticket-transfers/{id}/accept` or `/decline`; the sender can cancel with `DELETE /ticket-transfers/{id}`. Acceptance changes the registration owner and rotates the ticket credential in one transaction. Decline and cancellation leave ownership unchanged. Snapshot preparation or disabling event transfers expires pending requests. A pending request cannot be accepted after the event ends, but the sender can still cancel it and the recipient can decline it.

The organizer event page retains transfer status and transition timestamps for audit. History is paginated at 100 requests per page.

---

## 4. Staff Admission Endpoints

### 4.1 Assigned Events
Lists events where the authenticated staff member is assigned to scan tickets.

- **Method**: `GET`
- **Endpoint**: `/staff/events`
- **Access**: Authenticated Event Staff / Admin (`auth:sanctum`)
- **Gate visibility**: `access_points` is scoped to what the caller may actually operate.
  - A staff member bound to a specific gate receives only that gate.
  - A staff member assigned to the event without a specific gate receives all active gates.
  - Admins and the event organizer receive all active gates.

#### Success Response (`200 OK`) — staff member bound to Gate A
```json
{
  "events": [
    {
      "id": 1,
      "title": "MSEUF University Intramurals 2026",
      "status": "published",
      "venue": {
        "name": "MSEUF University Gymnasium",
        "location": "Lucena City"
      },
      "starts_at": "2026-10-03T09:00:00.000000Z",
      "ends_at": "2026-10-03T17:00:00.000000Z",
      "access_points": [
        { "id": 1, "name": "Entrance 1", "code": "ENTRANCE-1" }
      ]
    }
  ]
}
```

`GET /staff/events/{event}/access-points` applies the same scoping and returns `403 Forbidden` when the caller is not assigned to the event at all.

---

### 4.2 Preview an entry or exit (read-only)
Read-only validation checks the ticket and current presence before staff confirmation. It does not create an admission movement.

> **Important**: Ticket preview is read-only. The event's identity policy is either `standard` or `id_check`. Standard shows the attendee name and identifier; `id_check` requires an authorized staff member to acknowledge checking an approved ID before a movement can be recorded. Student email verification and a self-reported student number do not establish enrollment. Photo collection is not available until an approved privacy and retention policy exists.

- **Method**: `POST`
- **Endpoint**: `/staff/events/{event_id}/admissions/preview`
- **Access**: Authenticated Assigned Event Staff (`auth:sanctum`)

#### Request Body
```json
{
  "access_point_id": 1,
  "qr_payload": "ingress:v1:ING-A92B48EF12:6b86b273ff34fce19d6b804eff5a3f5747ada4eaa22f1d49c01e52ddb7875b4b",
  "direction": "entry"
}
```
*(Note: `client_scan_id` is NOT sent during preview because preview does not perform an admission attempt).*
`direction` accepts `entry` or `exit`; it defaults to `entry` for older clients.

#### Response 1: Valid Ticket (`200 OK`)
```json
{
  "status": "valid",
  "message": "Valid Ticket",
  "detail": "Compare the attendee name and identifier before confirming.",
  "direction": "entry",
  "ticket": {
    "public_id": "ING-A92B48EF12",
    "attendee_name": "Ron Vincent Cada",
    "student_id": "A23-35524",
    "profile_photo_url": null,
    "identity_verification_level": "standard"
  }
}
```

#### Response 2: Duplicate Admission / Anti-Passback (`409 Conflict`)
```json
{
  "status": "already_admitted",
  "message": "Already Checked In",
  "detail": "The attendee is already inside. Record an exit before another entry.",
  "previous_admission": {
    "direction": "entry",
    "admitted_at": "2026-10-01T01:42:00.000000Z",
    "access_point": "Entrance 1",
    "scanned_by": "Staff Alex Rivera"
  },
  "ticket": {
    "public_id": "ING-A92B48EF12",
    "attendee_name": "Ron Vincent Cada",
    "student_id": "A23-35524"
  }
}
```

---

### 4.3 Confirm and record an entry or exit
The authoritative transaction runs after staff confirmation. It revalidates the ticket, gate authorization, and presence transition while holding the ticket lock, then appends an `Admission` movement.

- **Method**: `POST`
- **Endpoint**: `/staff/events/{event_id}/admissions`
- **Access**: Authenticated Assigned Event Staff (`auth:sanctum`)

#### Request Body
```json
{
  "access_point_id": 1,
  "qr_payload": "ingress:v1:ING-A92B48EF12:6b86b273ff34fce19d6b804eff5a3f5747ada4eaa22f1d49c01e52ddb7875b4b",
  "client_scan_id": "c1f38e3a-4e2a-4a6c-9a1b-1234567890ab",
  "direction": "entry",
  "identity_check_confirmed": false
}
```
`identity_check_confirmed` is required and must be `true` when the event uses `id_check`. It is stored with the movement and synchronized to the cloud. Standard events should omit it or send `false`.
#### Response 1: Ticket Valid (`200 OK`)
```json
{
  "status": "admitted",
  "message": "Ticket Valid",
  "detail": "Admitted at 1:42 PM",
  "admission": {
    "id": 1,
    "direction": "entry",
    "identity_check_confirmed": false,
    "admitted_at": "2026-10-01T01:42:00.000000Z",
    "access_point": "Entrance 1",
    "scanned_by": "Staff Alex Rivera"
  },
  "ticket": {
    "public_id": "ING-A92B48EF12",
    "attendee_name": "Ron Vincent Cada",
    "student_id": "A23-35524"
  }
}
```

An exit confirmation returns `status: "exited"`, `message: "Exit Recorded"`, and `admission.direction: "exit"`.
An `id_check` event rejects confirmation with HTTP `422` and `status: "identity_check_required"` until the staff acknowledgment is present.

#### Response 2: Duplicate Admission / Anti-Passback (`409 Conflict`)
```json
{
  "status": "already_admitted",
  "message": "Already Checked In",
  "detail": "The attendee is already inside. Record an exit before another entry.",
  "previous_admission": {
    "direction": "entry",
    "admitted_at": "2026-10-01T01:42:00.000000Z",
    "access_point": "Entrance 1",
    "scanned_by": "Staff Alex Rivera"
  },
  "ticket": {
    "public_id": "ING-A92B48EF12",
    "attendee_name": "Ron Vincent Cada",
    "student_id": "A23-35524"
  }
}
```

Other movement-state conflicts return HTTP `409` with `status: "not_inside"` for an exit without a current entry, or `status: "reentry_not_allowed"` for an entry after exit when the event policy is disabled. Organizers set `re_entry_allowed` in event settings before preparing the node snapshot. It defaults to false and is frozen with the event snapshot.

#### Response 3: Invalid Ticket (`422 Unprocessable Content`)
```json
{
  "status": "invalid_ticket",
  "message": "Invalid Ticket",
  "detail": "This ticket could not be verified."
}
```

#### Response 4: Wrong Event (`422 Unprocessable Content`)
```json
{
  "status": "wrong_event",
  "message": "Wrong Event",
  "detail": "This ticket was issued for: COE Engineering Week Hackathon 2026."
}
```

#### Response 5: Unauthorized Staff (`403 Forbidden`)
```json
{
  "status": "unauthorized_staff",
  "message": "Unauthorized Staff",
  "detail": "You are not assigned to operate entrance scanners for this event."
}
```

#### Response 6: Unauthorized Access Point / Gate Mismatch (`403 Forbidden`)
Returned when staff bound to one gate attempt to scan through another.

```json
{
  "status": "gate_mismatch",
  "message": "Unauthorized Access Point",
  "detail": "You are assigned to scan at Gate A - West Wing. You cannot operate scanners at Gate B - East Wing."
}
```

#### Response 7: Idempotency Key Conflict (`409 Conflict`)
Returned when a `client_scan_id` is replayed for a different ticket, credential version, event, access point, or movement direction. A replay for the same request returns the original movement response instead.
An insert that loses a race on the `UNIQUE(client_scan_id)` index is also mapped to this response.
```json
{
  "status": "idempotency_conflict",
  "message": "Idempotency Key Conflict",
  "detail": "The client scan ID was already used for a different movement request."
}
```

#### Response 8: Event Cancelled (`422 Unprocessable Content`)
```json
{
  "status": "event_cancelled",
  "message": "Event Cancelled",
  "detail": "This event has been cancelled and cannot admit attendees."
}
```

#### Response 9: Event Completed (`422 Unprocessable Content`)
Returned when the event status is `completed`, **and also** once `now() > ends_at` (in which case the message is `Event Ended` and the detail names the closing time). `starts_at` is deliberately not enforced — doors may open before the scheduled start.

```json
{
  "status": "event_completed",
  "message": "Event Completed",
  "detail": "This event has already concluded."
}
```

```json
{
  "status": "event_completed",
  "message": "Event Ended",
  "detail": "Admission closed at 5:00 PM."
}
```

#### Response 10: Revoked Ticket (`422 Unprocessable Content`)
```json
{
  "status": "revoked_ticket",
  "message": "Revoked Ticket",
  "detail": "Ticket is revoked and not eligible for admission."
}
```

#### Response 11: Invalid Gate (`422 Unprocessable Content`)
Returned when the access point does not belong to the event or is inactive.

```json
{
  "status": "invalid_gate",
  "message": "Invalid Gate",
  "detail": "The selected access point is invalid or currently inactive."
}
```

#### Response 12: Event Not Found (`404 Not Found`)
```json
{
  "status": "not_found",
  "message": "Event Not Found",
  "detail": "The requested event does not exist."
}
```

### 4.3 Admission evaluation order

The server evaluates these conditions in order and stops at the first failure:

1. event exists
2. event is neither `cancelled` nor `completed`, and `now()` is not past `ends_at`
3. caller is the organizer, an admin, or assigned in `event_staff`
4. access point exists, belongs to the event, and is active
5. staff bound to a specific gate is scanning that gate
6. QR payload matches `ingress:v1:<public_id>:<64 hex secret>`
7. a ticket with that `public_id` exists
8. `sha256(secret)` matches the stored `qr_hash` (constant time)
9. registration is `confirmed`
10. ticket is `active`
11. ticket belongs to the selected event
12. `client_scan_id` is a replay only for the same ticket, credential version, event, gate, and direction
13. the requested direction is a valid state transition for the ticket

Steps 2, 8–11, 12, and 13 are re-evaluated authoritatively **inside** the admission transaction:
- Step 2 under an `Event::sharedLock()` (`FOR SHARE`) on the event row, so a concurrent event cancellation or completion cannot commit mid-admission.
- Steps 8–11 and 12–13 under a `Ticket::lockForUpdate()` (`FOR UPDATE`) on the ticket row, re-validating the secret, ticket status, registration status, event match, and in-lock idempotency.
Steps 1–11 outside the transaction serve as a fast pre-check path.

Step 13 uses the latest movement for the ticket across all entrances and credential versions. `entry` is rejected while the attendee is inside. `exit` is rejected while the attendee is outside. A later `entry` after an exit is accepted only when the event's `re_entry_allowed` policy is true. Credential versions stay on every movement so scan retries remain bound to the ticket issuance.

### Staff assignment navigation hint

The authenticated user resource includes `is_event_staff`, indicating whether the user currently has an event staff assignment. Clients may use it to show staff navigation. It is not an authorization grant; the node checks the assignment when issuing and using a node staff token.

### Event Node admission synchronization

`POST /node-sync/admissions` is available only on the cloud runtime and requires HTTPS outside local and testing environments. The node sends an encrypted, authenticated batch as `application/octet-stream` with its ID in `X-Ingress-Node`. Batch schema version 3 includes movement direction and the identity-check acknowledgment. Versions 1 and 2 remain readable, with no identity-check acknowledgment. The cloud checks the node's active event authority, frozen re-entry and identity policies, and movement sequence before applying the batch. A successful response returns a batch ID and authenticated receipt. Repeating the same batch returns the same receipt without duplicating movements. Invalid credentials, conflicting records, or malformed batches are rejected without partial writes.

### Organizer attendance feed

`GET /organizer/events/{event}/attendance/feed` reports confirmed registrations, attendees who have ever entered, current inside and outside counts, attendees not yet arrived, re-entry movements, and current inside counts for student accounts versus guests and alumni. The feed's `recent_admissions` list contains both `entry` and `exit` movements.

`GET /organizer/events/{event}/attendance.csv` downloads the full confirmed roster with current presence, entry/exit counts, last movement, gate, staff member, and the last ID-check acknowledgment. It is restricted to an organizer authorized for the event, uses `private, no-store`, and escapes spreadsheet formulas in attendee-controlled fields. The export contains personal information and must be stored and shared accordingly.

### Organizer-issued guest and alumni passes

The cloud organizer interface accepts `POST /organizer/events/{event}/guests` from an authorized organizer with `guest_name`, `guest_email`, and `attendee_type` (`guest` or `alumni`). It creates a registration without a user account and redirects to an authenticated printable pass. Issuance and pass viewing require HTTPS outside local and testing environments. The pass response is private and non-cacheable. The organizer must deliver it securely; email delivery is not implemented. Registration cutoff, duplicate identity checks, and event capacity apply.

### Node walk-in issuance

`POST /staff/events/{event}/walk-ins` requires an assigned node staff session and active authority for the event. Send `client_request_id` as a UUID, `name`, `email`, and `kind` as `student`, `guest`, or `alumni`. Include `student_id` for a student and omit it for other kinds. The node locks the event, checks existing registrations and remaining capacity, and returns `public_id`, `attendee_name`, `student_id`, and `qr_payload` with a private, no-store response. The request ID must be reused with the same details when retrying; a retry does not return a revoked pass. Issuance does not admit the attendee; scan preview and explicit confirmation remain required.

`POST /staff/events/{event}/walk-ins/recover` requires `email`, `identity_verified: true`, and a `reason` of `lost`, `damaged`, or `not_received`. Assigned staff must verify the attendee in person under the event's approved identity procedure; email alone is not proof. Do not collect identity document numbers or copies. The node records the issuance, staff user, recovery time, attestation, and reason. The pass is a bearer credential, so hand it directly to the attendee. The response also includes `student_id` when present. Recovery does not change admission status.

`POST /node-sync/walk-ins` accepts an encrypted node package on the cloud runtime under the same HTTPS and node-header rules as admission synchronization. The cloud checks the active authority, frozen snapshot, staff assignment, identity conflicts, and capacity. The response binds cloud registration and ticket IDs to the issuance and payload with an authenticated receipt. A retry of the same issuance returns the same IDs. The node synchronizes the walk-in before sending its admissions.

`POST /node-sync/walk-in-recoveries` accepts an encrypted recovery record after its issuance has synchronized. The cloud checks the node's event authority, the frozen staff assignment, and the issuance mapping. Repeating the same record returns the same authenticated receipt. The organizer event page shows up to 50 recent synchronized recoveries, including the staff attestation and reason; records can lag while the node is offline.
