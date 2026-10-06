# Ingress REST API Documentation (v1)

**Base URL**: `http://<LAN_SERVER_IP>:8000/api/v1`  
**Authentication**: Bearer Token via HTTP Header (`Authorization: Bearer <token>`)  
**Standard Headers**:
- `Accept: application/json`
- `Content-Type: application/json`

---

## 1. Authentication Endpoints

### 1.1 Mobile Login
Issues a personal access token for mobile attendees and event staff.

- **Method**: `POST`
- **Endpoint**: `/auth/login`
- **Access**: Public

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
Transactionally reserves a seat with pessimistic row lock and issues a digital ticket.

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
Returns all tickets issued to the authenticated student. Because the response contains only the caller's own tickets, each entry includes the reusable `qr_payload`.

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
Retrieves single ticket details. The reusable `qr_payload` is returned **only to the attendee who owns the ticket**.

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

### 4.2 Preview & Validate Ticket (Read-Only)
Read-only validation of an attendee ticket before staff confirmation. Validates ticket authenticity, registration, and anti-passback rules without consuming the ticket or creating an admission record.

> **Important**: Ticket validation does not create an admission. Admission occurs only after authorized entrance staff confirms the attendee identity against their university ID. Profile photos are optional (nullable) and are never encoded in the QR code.

- **Method**: `POST`
- **Endpoint**: `/staff/events/{event_id}/admissions/preview`
- **Access**: Authenticated Assigned Event Staff (`auth:sanctum`)

#### Request Body
```json
{
  "access_point_id": 1,
  "qr_payload": "ingress:v1:ING-A92B48EF12:6b86b273ff34fce19d6b804eff5a3f5747ada4eaa22f1d49c01e52ddb7875b4b"
}
```
*(Note: `client_scan_id` is NOT sent during preview because preview does not perform an admission attempt).*

#### Response 1: Valid Ticket (`200 OK`)
```json
{
  "status": "valid",
  "message": "Valid Ticket",
  "detail": "Verify the attendee identity before confirming admission.",
  "ticket": {
    "public_id": "ING-A92B48EF12",
    "attendee_name": "Ron Vincent Cada",
    "student_id": "A23-35524",
    "profile_photo_url": null
  }
}
```

#### Response 2: Duplicate Admission / Anti-Passback (`409 Conflict`)
```json
{
  "status": "already_admitted",
  "message": "Already Checked In",
  "detail": "Previously admitted at Entrance 1, 1:42 PM",
  "previous_admission": {
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

### 4.3 Confirm & Record Ticket Admission
Authoritative admission transaction executed upon staff confirmation (`CONFIRM ADMISSION`). Revalidates mutable state under lock, enforces anti-passback, creates the `Admission` record, and returns the admitted response.

- **Method**: `POST`
- **Endpoint**: `/staff/events/{event_id}/admissions`
- **Access**: Authenticated Assigned Event Staff (`auth:sanctum`)

#### Request Body
```json
{
  "access_point_id": 1,
  "qr_payload": "ingress:v1:ING-A92B48EF12:6b86b273ff34fce19d6b804eff5a3f5747ada4eaa22f1d49c01e52ddb7875b4b",
  "client_scan_id": "c1f38e3a-4e2a-4a6c-9a1b-1234567890ab"
}
```
#### Response 1: Ticket Valid (`200 OK`)
```json
{
  "status": "admitted",
  "message": "Ticket Valid",
  "detail": "Admitted at 1:42 PM",
  "admission": {
    "id": 1,
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

#### Response 2: Duplicate Admission / Anti-Passback (`409 Conflict`)
```json
{
  "status": "already_admitted",
  "message": "Already Checked In",
  "detail": "Previously admitted at Entrance 1, 1:42 PM",
  "previous_admission": {
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
Returned when a `client_scan_id` is replayed for a **different** ticket, credential version, event, or access point. A replay for the *same* ticket/credential/event/gate returns the original `200 Ticket Valid` response instead.
An insert that loses a race on the `UNIQUE(client_scan_id)` index is also mapped to this response.
```json
{
  "status": "idempotency_conflict",
  "message": "Idempotency Key Conflict",
  "detail": "The client scan ID was already used for a different admission request."
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
12. `client_scan_id` is a replay only for the same ticket, credential version, event, and gate
13. no prior admission exists for this ticket (physical anti-passback)

Steps 2, 8–11, 12, and 13 are re-evaluated authoritatively **inside** the admission transaction:
- Step 2 under an `Event::sharedLock()` (`FOR SHARE`) on the event row, so a concurrent event cancellation or completion cannot commit mid-admission.
- Steps 8–11 and 12–13 under a `Ticket::lockForUpdate()` (`FOR UPDATE`) on the ticket row, re-validating the secret, ticket status, registration status, event match, and in-lock idempotency.
Steps 1–11 outside the transaction serve as a fast pre-check path.

Step 13 verifies that no prior admission exists for the ticket across all entrances and credential versions, preventing duplicate admission. Credential versions are stored on admission records to preserve issuance history and scope idempotency replays.
