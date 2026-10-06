<p align="center">
  <img src="ingress_logo.png" alt="Ingress" width="320">
</p>

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
  ↓ Provision access points (Entrance 1, Entrance 2, Entrance 3)
  ↓ Assign event staff
  ↓ Publish Event

           ↓

STUDENT MOBILE (Expo React Native)
Login (a23-35524@student.mseuf.edu.ph - Ron Vincent Cada)
  ↓ Discover Event
  ↓ View Event Details
  ↓ Register (Pessimistic row lock strictly guarantees confirmed <= capacity)
  ↓ Receive Opaque Digital Ticket
  ↓ Display High-Contrast QR Code

           ↓

STAFF PHONE — ENTRANCE 1 (Mobile Scanner)
Login (staff.gate_a@mseuf.edu.ph)
  ↓ Select Assigned Event (MSEUF University Intramurals 2026)
  ↓ Select Entrance 1
  ↓ Scan Ticket
  ↓ [✓] TICKET VALID (Admission recorded transactionally)

           ↓

STAFF PHONE — ENTRANCE 2 (Anti-Passback)
Login (staff.gate_b@mseuf.edu.ph)
  ↓ Scan exact same ticket at Entrance 2
  ↓ [!] ALREADY CHECKED IN (Report previous admission: Entrance 1, previous timestamp)
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
│   ├── public/images/    # Generated Ingress brand assets (lockup, mark, favicon)
│   └── tests/Feature/    # 61 feature tests covering all guarantees
├── mobile/               # Expo React Native Mobile Application
│   ├── app/              # Expo Router file-based pages
│   │   ├── (attendee)/   # Discover catalog, Event detail, Ticket wallet, QR view, Profile
│   │   ├── (staff)/      # Assigned events, Multi-gate viewfinder, Scanner feedback
│   │   ├── login.tsx     # MSEUF-inspired login with demo switcher
│   │   └── server-settings.tsx # In-app LAN server configurator & connection tester
│   ├── assets/           # Generated app icon, adaptive icon layers, favicon
│   ├── tests/            # Node-run session checks (no JS test runner in the app)
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

## Demo Mode and Demo Accounts

The synthetic demonstration accounts below exist **only in demo mode**. Copying `api/.env.example` enables it (`INGRESS_DEMO_MODE=true`), which keeps the quickstart below working out of the box. The setting defaults to **off**, so an environment that does not opt in creates no predictable credential, and enabling it while `APP_ENV=production` is a hard boot error.

Outside demo mode there are no accounts at all. Provision the first administrator yourself:

```bash
cd api
php artisan tinker --execute="\App\Models\User::create(['name'=>'Event Administrator','email'=>'you@mseuf.edu.ph','role'=>'admin','password'=>bcrypt('a-real-password')]);"
```

| Role | Name | Email | Usage |
| :--- | :--- | :--- | :--- |
| **Admin** | MSEUF Administrator | `admin@mseuf.edu.ph` | Web Management |
| **Organizer** | Prof. Juan Dela Cruz (CCS) | `organizer@mseuf.edu.ph` | Web Organizer Portal |
| **Organizer 2**| Engr. Maria Santos (COE) | `organizer2@mseuf.edu.ph` | Web Organizer Portal |
| **Staff Entrance 1**| Staff Alex Rivera | `staff.gate_a@mseuf.edu.ph` | Mobile Entrance 1 Scanner |
| **Staff Entrance 2**| Staff Bea Ramos | `staff.gate_b@mseuf.edu.ph` | Mobile Entrance 2 Scanner |
| **Staff Entrance 3**| Staff Chris Dizon | `staff.gate_c@mseuf.edu.ph` | Mobile Entrance 3 Scanner |
| **Student 1 (Primary)**| Ron Vincent Cada (`A23-35524`)| `a23-35524@student.mseuf.edu.ph`| Mobile Attendee App |
| **Student 2** | Patricia Reyes (`2024-01002`)| `student2@mseuf.edu.ph` | Mobile Attendee App |
| **Student 3** | Mark Aquino (`2024-01003`) | `student3@mseuf.edu.ph` | Mobile Attendee App |

All demo accounts use the standard password: `password`. The mobile demo quick-select and pre-filled password appear only when the app is built with `EXPO_PUBLIC_INGRESS_DEMO_MODE=true` (see `mobile/.env.example`).

> **Transport security**: the LAN procedure in this README and in `docs/lan-demo.md` uses plain HTTP over a controlled local network, which is fine for a prototype demonstration. Production Event Node traffic must use HTTPS/TLS — passwords, bearer tokens, QR credentials, and identity data must not cross the network in cleartext. See ADR 009 in `docs/design-decisions.md`.

---

## Automation Scripts (`scripts/`)

PowerShell automation scripts are provided in the `scripts/` directory for convenient one-step execution:

| Script | Purpose | Execution Command |
| :--- | :--- | :--- |
| **`start-all.ps1`** | **Master Launcher**: Starts PostgreSQL, launches Laravel Web API and Expo Mobile Bundler in separate console windows, and opens the organizer portal | `powershell -ExecutionPolicy Bypass -File scripts\start-all.ps1` |
| **`start-web.ps1`** | Starts PostgreSQL and launches Laravel Web/API on `0.0.0.0:8000` | `powershell -ExecutionPolicy Bypass -File scripts\start-web.ps1` |
| **`start-mobile.ps1`** | Starts the Expo React Native mobile bundler | `powershell -ExecutionPolicy Bypass -File scripts\start-mobile.ps1` |
| **`test-all.ps1`** | Runs all 4 test suites (Backend, Concurrency, Mobile Session, TypeScript) | `powershell -ExecutionPolicy Bypass -File scripts\test-all.ps1` |
| **`setup.ps1`** | One-time environment setup wizard (Composer, npm, .env, migrations) | `powershell -ExecutionPolicy Bypass -File scripts\setup.ps1` |
| **`start-db.ps1`** | Starts the local PostgreSQL 18 database cluster on port `5433` | `powershell -ExecutionPolicy Bypass -File scripts\start-db.ps1` |
| **`stop-db.ps1`** | Gracefully stops the PostgreSQL 18 database | `powershell -ExecutionPolicy Bypass -File scripts\stop-db.ps1` |
| **`find-ip.ps1`** | Detects active Wi-Fi IPv4 address and displays mobile base URL | `powershell -ExecutionPolicy Bypass -File scripts\find-ip.ps1` |

Keep these files ASCII-only. Windows PowerShell 5.1 reads BOM-less files as ANSI, so a UTF-8 character such as an em dash is decoded into bytes that include a `"`, which closes a string literal early and breaks parsing.

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
The fast feature suite runs on in-memory SQLite (61 tests, 330 assertions):
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

SQLite serializes writers, so it cannot prove real race behaviour. Six guarantees
are verified against the real PostgreSQL server using genuinely overlapping
transactions released through a two-process barrier:

1. two simultaneous registrations competing for the final seat
2. two simultaneous gates scanning the same unused ticket
3. the same `client_scan_id` replayed for two different tickets
4. a worker forced to reach the admission insert and collide on the
   `admissions.client_scan_id` unique index (proves the conflict mapping, not just
   whichever branch the scheduler happened to pick)
5. an event cancellation held open while a scan is in flight — the scan blocks and is then
   rejected, proving event cancellation cannot race through admission
6. two different tickets scanned while a shared event lock is held — both are
   admitted, proving scanners are not serialized behind each other

```bash
cd api
php scripts/verify_pgsql_concurrency.php
```

Cases 4 and 5 hold an uncommitted conflicting write from a second connection, wait
until PostgreSQL reports the worker blocked (`pg_stat_activity`), then release it.
The script provisions its own `ingress_concurrency` database (recreated on each run)
so demonstration data is never touched, and exits non-zero if any invariant is
violated. Override the database name with `INGRESS_CONCURRENCY_DB` if needed.

#### Mobile session checks

The mobile app has no JavaScript test runner, so its session rules are checked by a
small dependency-free script that runs the real service modules under Node with the
two native packages stubbed:

```bash
cd mobile
node tests/session.test.mjs
```

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

### 5. Standalone Android APK Build (EAS)

To produce an installable standalone APK for physical Android devices that runs independently without Expo Go or the Metro development server:

```bash
cd mobile
npm install
eas login
eas build -p android --profile preview
```

**Important Operational Notes:**
- **Build Creation**: EAS Build compiles the native Android package in the cloud, so an internet connection is required while generating and downloading the APK.
- **No Expo Go or Metro Dependency**: The resulting `.apk` embeds the JavaScript bundle. Once installed, it launches directly on Android without requiring Expo Go or a running Metro bundler.
- **LAN Communication**: The installed app communicates directly with the Laravel REST API over the local Wi-Fi or mobile hotspot.
- **Network Requirement**: Both the Android phone and the laptop hosting Laravel must be connected to the same local network. Start the backend bound to all interfaces (`0.0.0.0:8000`), for example with `powershell -File scripts\start-web.ps1`.
- **Server URL Configuration**: On the login screen of the installed app, tap **Change Server IP**, enter `http://<LAPTOP_LAN_IP>:8000/api/v1` (using the laptop's real Wi-Fi IPv4 address from `find-ip.ps1`, **not** `localhost`), tap **Test Connection**, and tap **Save URL**.
- **Native Cleartext HTTP Support**: Standalone Android releases disable unencrypted HTTP by default. Ingress configures native `android.usesCleartextTraffic: true` via the `expo-build-properties` plugin in `mobile/app.json` so the APK can connect to the local event server over plain HTTP.

---
## Detailed Documentation Links

- [System Architecture Specification](docs/architecture.md)
- [REST API Reference](docs/api.md)
- [Classroom LAN Defense Guide](docs/lan-demo.md)
- [Architecture Decision Records (ADRs)](docs/design-decisions.md)
- [Demo Data & Official MSEUF Sources](docs/demo-data.md)
