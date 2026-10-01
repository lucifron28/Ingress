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
  "email": "student1@mseuf.edu.ph",
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
    "id": 6,
    "name": "Carlo Mendoza",
    "email": "student1@mseuf.edu.ph",
    "role": "student",
    "student_id": "2024-01001",
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
      "title": "MSEUF Annual Tech Symposium 2026",
      "slug": "mseuf-annual-tech-symposium-2026",
      "description": "Keynotes, student exhibitions, and industry networking.",
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
    "title": "MSEUF Annual Tech Symposium 2026",
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

### 3.2 Cancel Registration
Cancels registration and revokes the active ticket.

- **Method**: `DELETE`
- **Endpoint**: `/events/{id}/registration`
- **Access**: Authenticated (`auth:sanctum`)

#### Success Response (`200 OK`)
```json
{
  "message": "Registration cancelled and digital ticket revoked."
}
```

---

### 3.3 My Tickets (Ticket Wallet)
Returns all tickets issued to the authenticated student.

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
        "title": "MSEUF Annual Tech Symposium 2026",
        "venue": {
          "id": 1,
          "name": "MSEUF University Gymnasium",
          "location": "Lucena City"
        },
        "starts_at": "2026-10-03T09:00:00.000000Z",
        "ends_at": "2026-10-03T17:00:00.000000Z"
      },
      "attendee": {
        "name": "Carlo Mendoza",
        "student_id": "2024-01001",
        "email": "student1@mseuf.edu.ph"
      }
    }
  ]
}
```

---

### 3.4 Ticket Details
Retrieves single ticket details including opaque QR payload.

- **Method**: `GET`
- **Endpoint**: `/tickets/{id}`
- **Access**: Authenticated Ticket Owner, Staff, Organizer, or Admin

---

## 4. Staff Admission Endpoints

### 4.1 Assigned Events
Lists events where the authenticated staff member is assigned to scan tickets.

- **Method**: `GET`
- **Endpoint**: `/staff/events`
- **Access**: Authenticated Event Staff / Admin (`auth:sanctum`)

#### Success Response (`200 OK`)
```json
{
  "events": [
    {
      "id": 1,
      "title": "MSEUF Annual Tech Symposium 2026",
      "venue": {
        "name": "MSEUF University Gymnasium",
        "location": "Lucena City"
      },
      "starts_at": "2026-10-03T09:00:00.000000Z",
      "ends_at": "2026-10-03T17:00:00.000000Z",
      "access_points": [
        { "id": 1, "name": "Main Entrance", "code": "MAIN" },
        { "id": 2, "name": "Gate A - West Wing", "code": "GATE-A" },
        { "id": 3, "name": "Gate B - East Wing", "code": "GATE-B" }
      ]
    }
  ]
}
```

---

### 4.2 Validate & Record Ticket Admission
Server-authoritative ticket scan validation with anti-passback and idempotency checks.

- **Method**: `POST`
- **Endpoint**: `/staff/events/{event_id}/admissions`
- **Access**: Authenticated Assigned Event Staff (`auth:sanctum`)

#### Request Body
```json
{
  "access_point_id": 2,
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
    "access_point": "Gate A - West Wing",
    "scanned_by": "Staff Alex Rivera"
  },
  "ticket": {
    "public_id": "ING-A92B48EF12",
    "attendee_name": "Carlo Mendoza",
    "student_id": "2024-01001"
  }
}
```

#### Response 2: Duplicate Admission / Anti-Passback (`409 Conflict`)
```json
{
  "status": "already_admitted",
  "message": "Already Checked In",
  "detail": "Previously admitted at Gate A - West Wing, 1:42 PM",
  "previous_admission": {
    "admitted_at": "2026-10-01T01:42:00.000000Z",
    "access_point": "Gate A - West Wing",
    "scanned_by": "Staff Alex Rivera"
  },
  "ticket": {
    "public_id": "ING-A92B48EF12",
    "attendee_name": "Carlo Mendoza",
    "student_id": "2024-01001"
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
