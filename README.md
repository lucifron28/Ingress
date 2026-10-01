# Ingress: LAN-Enabled Web & Mobile Event Ticketing System

**Manuel S. Enverga University Foundation (MSEUF)**  
*Event Ticketing, Admission, and Attendance Management System*

Ingress is a high-reliability, LAN-operable event platform designed for campus events at MSEUF. It combines a Laravel 13 web management console for organizers with an Expo React Native mobile application for student discovery, digital ticketing, and multi-gate admission scanning.

A key feature is **LAN-enabled event operation**: multiple gate staff phones connect to a local Ingress event server and validate tickets against one shared source of truth without requiring an active internet connection.

---

## 50% Milestone Vertical Slice

The completed 50% milestone demonstrates the full end-to-end lifecycle across roles:

```
ORGANIZER WEB (Blade)
Login (organizer@mseuf.edu.ph)
  ↓ Create Event & configure capacity
  ↓ Provision access points (Gate A, Gate B)
  ↓ Assign event staff
  ↓ Publish Event

           ↓

STUDENT MOBILE (Expo React Native)
Login (student1@mseuf.edu.ph)
  ↓ Discover Event
  ↓ View Event Details
  ↓ Register (Pessimistic row lock strictly guarantees confirmed <= capacity)
  ↓ Receive Opaque Digital Ticket
  ↓ Display High-Contrast QR Code

           ↓

STAFF PHONE — GATE A (Mobile Scanner)
Login (staff.gate_a@mseuf.edu.ph)
  ↓ Select Assigned Event
  ↓ Select Gate A
  ↓ Scan Ticket
  ↓ [✓] TICKET VALID (Admission recorded transactionally)

           ↓

STAFF PHONE — GATE B (Anti-Passback)
Login (staff.gate_b@mseuf.edu.ph)
  ↓ Scan exact same ticket at Gate B
  ↓ [!] ALREADY CHECKED IN (Report previous admission: Gate A, previous timestamp)
  ↓ No duplicate admission recorded

           ↓

ORGANIZER WEB (Attendance View)
Dashboard updates in real-time
  ↓ Checked-in count increments
  ↓ Gate throughput breakdown reflects admission
  ↓ Live audit feed records timestamp & gate
```

---

## Technology Stack & Exact Versions

| Component | Technology | Exact Version | Selection Justification |
| :--- | :--- | :--- | :--- |
| **Backend Framework** | Laravel | `13.34.0` | Latest stable release; slim skeleton; native Sanctum token integration; Eloquent locking. |
| **PHP Runtime** | PHP | `8.4.24` | Modern property hooks, typed properties, native enums with model casts. |
| **Database** | PostgreSQL | `18.4` | Native `timestamptz`, JSONB, atomic transaction isolation, robust row-level locking. |
| **Web UI** | Laravel Blade | Built-in | Server-rendered admin console optimized for desktop/tablet event management without SPA overhead. |
| **Authentication** | Laravel Sanctum | `4.3.3` | Lightweight bearer token generation and revocation for mobile devices. |
| **Mobile Framework** | React Native / Expo | Expo `57.0.26`, RN `0.86.3` | Cross-platform runtime with robust native hardware APIs. |
| **Mobile Navigation** | Expo Router | `57.0.24` | File-based routing matching modern React Native standards. |
| **Secure Token Storage**| `expo-secure-store` | `57.0.4` | Hardware-backed keystore/keychain encryption for mobile credentials. |
| **Camera Barcode Scan**| `expo-camera` | `57.0.6` | CameraView component with high-throughput native QR code detection. |
| **QR Code Generation** | `react-native-qrcode-svg` | `6.3.26` | Maintained pure vector SVG QR code generation without remote image dependencies. |
| **Vector Primitives** | `react-native-svg` | `15.15.5` | Native SVG rendering engine backing the QR component. |
| **Concurrency Verification** | `php scripts/verify_pgsql_concurrency.php` | — | Two-process barrier harness proving final-seat and same-ticket races on real PostgreSQL. |

---

## Directory Structure

```
ingress/
├── api/                  # Laravel 13 Monolith & REST API
│   ├── app/
│   │   ├── Actions/      # RegisterForEvent, AdmitTicket
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── Api/V1/    # Auth, Event, Registration, Ticket, Staff
│   │   │   │   └── Web/       # Auth, OrganizerEvent, OrganizerAttendance
│   │   │   └── Resources/V1/  # User, Event, Venue, Ticket JSON envelopes
│   │   ├── Models/       # User, Venue, Event, AccessPoint, EventStaff, Registration, Ticket, Admission
│   │   └── Policies/     # EventPolicy, User authorization
│   ├── database/
│   │   ├── migrations/   # Relational schema with foreign keys and unique constraints
│   │   └── seeders/      # Synthetic demonstration accounts and campus events
│   ├── resources/views/  # Organizer Blade layouts & attendance command center
│   └── tests/Feature/    # 35 feature tests covering all guarantees
├── mobile/               # Expo React Native Mobile Application
│   ├── app/              # Expo Router file-based pages
│   │   ├── (attendee)/   # Discover catalog, Event detail, Ticket wallet, QR view, Profile
│   │   ├── (staff)/      # Assigned events, Multi-gate viewfinder, Scanner feedback
│   │   ├── login.tsx     # MSEUF-inspired login with demo switcher
│   │   └── server-settings.tsx # In-app LAN server configurator & connection tester
│   └── src/
│       ├── context/      # AuthContext session management
│       └── services/     # api-client (dynamic LAN resolution) & auth-storage (SecureStore)
├── docs/                 # In-depth architectural & operational specifications
│   ├── architecture.md   # Topology, schemas, concurrency, and security pipelines
│   ├── api.md            # Complete REST API reference with schemas and status codes
│   ├── lan-demo.md       # Classroom defense demonstration guide & offline LAN steps
│   └── design-decisions.md # Architecture Decision Records (ADRs)
└── README.md
```

---

## Pre-Configured Demo Accounts

All demo accounts use the standard password: `password`.

| Role | Name | Email | Usage |
| :--- | :--- | :--- | :--- |
| **Admin** | MSEUF Administrator | `admin@mseuf.edu.ph` | Web Management |
| **Organizer** | Prof. Juan Dela Cruz (CCS) | `organizer@mseuf.edu.ph` | Web Organizer Portal |
| **Organizer 2**| Engr. Maria Santos (COE) | `organizer2@mseuf.edu.ph` | Web Organizer Portal |
| **Staff Gate A**| Staff Alex Rivera | `staff.gate_a@mseuf.edu.ph` | Mobile Gate A Scanner |
| **Staff Gate B**| Staff Bea Ramos | `staff.gate_b@mseuf.edu.ph` | Mobile Gate B Scanner |
| **Student 1** | Carlo Mendoza (`2024-01001`) | `student1@mseuf.edu.ph` | Mobile Attendee App |
| **Student 2** | Patricia Reyes (`2024-01002`)| `student2@mseuf.edu.ph` | Mobile Attendee App |
| **Student 3** | Mark Aquino (`2024-01003`) | `student3@mseuf.edu.ph` | Mobile Attendee App |

---

## Quick Start & Verification Instructions

### 1. Database Setup (PostgreSQL 18)
PostgreSQL runs on port `5433` (or default `5432`) with database `ingress`:
```cmd
"C:\Program Files\PostgreSQL\18\bin\psql.exe" -h 127.0.0.1 -p 5433 -U postgres -d ingress -c "SELECT 1;"
```
If starting a new cluster:
```cmd
"C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe" -D "C:\Users\Ron\ingress_db" -l "C:\Users\Ron\ingress_db\server.log" start
```

### 2. Backend Server (Laravel 13)
```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=0.0.0.0 --port=8000
```
Organizer portal available at: `http://localhost:8000/login`

### 3. Run Backend Integration Tests
The fast feature suite runs on in-memory SQLite (50 tests, 265 assertions):
```bash
cd api
php artisan test
```

Run specific behavioural suites:
```bash
php artisan test --filter=EndToEndVerticalSliceTest
php artisan test --filter=AdmissionTest
php artisan test --filter=RegistrationTest
```

#### PostgreSQL concurrency verification (separate)

SQLite serializes writers, so it cannot prove real race behaviour. Three guarantees
are verified against the real PostgreSQL server using genuinely overlapping
transactions released through a two-process barrier:

1. two simultaneous registrations competing for the final seat
2. two simultaneous gates scanning the same unused ticket
3. the same `client_scan_id` replayed for two different tickets

```bash
cd api
php scripts/verify_pgsql_concurrency.php
```

The script provisions its own `ingress_concurrency` database (recreated on each run)
so demonstration data is never touched, and exits non-zero if any invariant is
violated. Override the database name with `INGRESS_CONCURRENCY_DB` if needed.

### 4. Mobile Client (Expo React Native)
```bash
cd mobile
npm install
cp .env.example .env      # optional: preset EXPO_PUBLIC_API_URL for this build
npx expo start
```
- On the mobile login screen, tap **Change Server IP**.
- Enter your laptop's LAN IP address, for example `http://<LAN_IP>:8000/api/v1`.
  The starting value can also be preset at build time with the `EXPO_PUBLIC_API_URL`
  environment variable in `mobile/.env`, so each demo build ships with its own Event
  Node address instead of a hardcoded developer machine.
- Tap **Test Connection** &rarr; **Save URL**.

---

## Detailed Documentation Links

- [System Architecture Specification](docs/architecture.md)
- [REST API Reference](docs/api.md)
- [Classroom LAN Defense Guide](docs/lan-demo.md)
- [Architecture Decision Records (ADRs)](docs/design-decisions.md)
