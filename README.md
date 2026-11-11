<p align="center">
  <img src="ingress_logo.png" alt="Ingress" width="320">
</p>

# Ingress: Web Ticketing and LAN Admission Platform

**Manuel S. Enverga University Foundation (MSEUF)**  
*Event Ticketing, Admission, and Attendance Management System*

Ingress is a LAN-operable event platform for campus events at MSEUF. Laravel 13 serves the Cloud website for students and organizers. The Expo React Native app is a staff-only scanner for the separate Event Node.

A key feature is **LAN-enabled event operation**: multiple gate staff phones connect to a local Ingress event server and validate tickets against one shared source of truth without requiring an active internet connection.

---

## Current system

Ingress separates online ticketing from event admission. The cloud handles student account activation, event discovery, registration, ticket transfers, organizer tools, and attendance reporting. Each Event Node has a separate PostgreSQL database and becomes the admission authority for its event after it imports a verified snapshot and activates the signed authority grant.

Staff scanners connect to the Event Node over the venue LAN. Public internet is not required to admit attendees, but the Event Node and venue LAN must be available. The mobile app stores only its Event Node staff session. Students use the website for accounts, registration, ticket access, and transfers. They can save a ticket PDF before the event and display its QR offline; the Event Node still makes the admission decision.

```text
Student website --HTTPS--> Cloud runtime --organizer tools and ticketing
                             |
                             | encrypted snapshot, receipts, authority grant
                             v
Staff-only scanner app --venue LAN--> Event Node --local PostgreSQL
                                  |
                                  | authenticated retryable sync after reconnect
                                  v
                              Cloud runtime
```

### Implemented capabilities

- Institutional student signup verifies control of an `@student.mseuf.edu.ph` email with a single use link. The student number remains self reported and does not prove enrollment.
- Cloud registration enforces event capacity transactionally. Student tickets use static opaque QR credentials.
- Organizers can issue printable guest and alumni tickets. Event staff can register walk ins on the Event Node under the same capacity and ticket validation rules.
- Staff authenticate with Event Node credentials or pair using a one use QR code. Admission scans preview ticket and attendee details without changing state. Only an explicit staff confirmation records an entry or exit.
- Events can allow or prohibit re entry and can require staff to confirm an approved ID check for every movement. The system does not collect attendee photos.
- Ticket transfers require recipient acceptance, rotate the QR credential, and retain an organizer audit history. Transfers close when the event snapshot is prepared.
- Event Nodes retain admission records while the cloud is unavailable and synchronize them with authenticated, idempotent batches after connectivity returns.
- Organizers can view current attendance and download a private CSV roster with attendee and movement data.
- Production configuration requires debug and demo mode off, uses exact CORS origin allowlists, supports trusted proxy configuration, and defaults session cookies to secure under HTTPS.

### Remaining limits

Snapshot delivery is a manual operator workflow. Safe recovery or authority transfer after a node grant is not implemented. Snapshot and imported roster deletion need an institution-approved retention policy and an operator procedure. Existing single-runtime databases do not have a safe migration path to the cloud/node split. Organizers deliver guest and alumni passes manually through an approved private channel; automated pass email and real payment-provider integration are not implemented. Paid checkout is simulated and charges no money. Real-device, venue-network, printer, and end-to-end verification remains with the project owner. Follow the [physical-device and venue test plan](docs/physical-device-test-plan.md) for the staging run.

See [the current architecture](docs/second-half-architecture.md), [deployment guide](docs/deployment.md), [API reference](docs/api.md), and [implementation roadmap](docs/roadmap.md) for operational details and remaining work.

---

## Stack versions

| Component | Technology | Exact Version | Selection Justification |
| :--- | :--- | :--- | :--- |
| **Backend Framework** | Laravel | `13.34.0` | Latest stable release; slim skeleton; native Sanctum token integration; Eloquent locking. |
| **PHP Runtime** | PHP | `8.4.24` | Modern property hooks, typed properties, native enums with model casts. |
| **Database** | PostgreSQL | `18.4` | Native `timestamptz`, JSONB, atomic transaction isolation, robust row-level locking. |
| **Web UI** | Laravel Blade | Built-in | Server-rendered admin console optimized for desktop/tablet event management without SPA overhead. |
| **Authentication** | Laravel Sanctum | `4.3.3` | Lightweight bearer token generation and revocation for mobile devices. |
| **Mobile Framework** | React Native / Expo | Expo `58.0.23`, RN `0.88.1` | Cross-platform mobile runtime. |
| **Mobile Navigation** | Expo Router | `58.0.30` | File-based routing. |
| **Secure Token Storage**| `expo-secure-store` | `58.0.2` | Native secure storage for mobile credentials. |
| **Camera Barcode Scan**| `expo-camera` | `58.0.14` | Native QR code scanning. |
| **QR Code Generation** | `react-native-qrcode-svg` | `6.3.26` | Maintained pure vector SVG QR code generation without remote image dependencies. |
| **Vector Primitives** | `react-native-svg` | `15.16.0` | Native SVG rendering engine backing the QR component. |
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
│   └── tests/Feature/    # Laravel feature tests for backend behavior
├── mobile/               # Expo React Native staff scanner
│   ├── src/app/          # Expo Router staff login, events, scan, and walk-in routes
│   ├── plugins/          # Isolated host-scoped Android LAN-demo CA config
│   ├── tests/            # Node session, route, and build security checks
│   ├── assets/           # Generated app icon, adaptive icon layers, favicon
│   ├── context/          # Node staff session management
│   └── services/         # HTTPS Event Node requests and secure session storage
├── docs/                 # In-depth architectural & operational specifications
│   ├── architecture.md   # Topology, schemas, concurrency, and security pipelines
│   ├── api.md            # Complete REST API reference with schemas and status codes
│   ├── lan-demo.md       # Classroom defense demonstration guide & offline LAN steps
│   └── design-decisions.md # Architecture Decision Records (ADRs)
└── README.md
```

---

## Demo Mode and Demo Accounts

Demo mode is for local development only. The `.env.example` opt-in creates predictable demo credentials and sample events; `APP_ENV=production` refuses to start when demo mode is enabled. Never enable it on Render or an Event Node. The current seeded sample data is documented in [Demo Data](docs/demo-data.md).

Do not create cloud administrators with Tinker or public registration. From a trusted Windows checkout, use `api\scripts\create_render_admin.ps1` for the first administrator. Use `api\scripts\provision_render_staging_account.ps1` for synthetic organizer, staff, or student accounts and reference venues. Use `api\scripts\register_render_node.ps1` to register an Event Node. Each script requires the cloud HTTPS origin, Neon direct URL with verified TLS, and a local trusted CA bundle; passwords and database secrets are prompted locally. Follow [Deployment and Operations](docs/deployment.md) and run these scripts only against the designated staging database.

> **Transport security**: the legacy web demo can use HTTP on an isolated development LAN. The current mobile scanner requires HTTPS in every build. Production Cloud and Event Node traffic must use trusted HTTPS certificates. Do not send passwords, bearer tokens, QR credentials, or identity data over cleartext connections. See ADR 009 and ADR 020 in `docs/design-decisions.md`.

---

## Automation Scripts (`scripts/`)

PowerShell automation scripts are provided in the `scripts/` directory for convenient one-step execution:

| Script | Purpose | Execution Command |
| :--- | :--- | :--- |
| **`start-all.ps1`** | **Master Launcher**: Starts PostgreSQL, launches Laravel Web API and Expo Mobile Bundler in separate console windows, and opens the organizer portal | `powershell -ExecutionPolicy Bypass -File scripts\start-all.ps1` |
| **`start-web.ps1`** | Starts PostgreSQL and launches Laravel Web/API on `0.0.0.0:8000` | `powershell -ExecutionPolicy Bypass -File scripts\start-web.ps1` |
| **`start-mobile.ps1`** | Starts the Expo React Native mobile bundler | `powershell -ExecutionPolicy Bypass -File scripts\start-mobile.ps1` |
| **`test-all.ps1`** | Runs backend, PostgreSQL concurrency and recovery, mobile session/route/build checks, lint, and TypeScript | `powershell -ExecutionPolicy Bypass -File scripts\test-all.ps1` |
| **`setup.ps1`** | One-time environment setup wizard (Composer, npm, .env, migrations) | `powershell -ExecutionPolicy Bypass -File scripts\setup.ps1` |
| **`start-db.ps1`** | Starts the local PostgreSQL 18 database cluster on port `5433` | `powershell -ExecutionPolicy Bypass -File scripts\start-db.ps1` |
| **`stop-db.ps1`** | Gracefully stops the PostgreSQL 18 database | `powershell -ExecutionPolicy Bypass -File scripts\stop-db.ps1` |
| **`find-ip.ps1`** | Detects the active Wi-Fi IPv4 address for local web development | `powershell -ExecutionPolicy Bypass -File scripts\find-ip.ps1` |

Keep these files ASCII-only. Windows PowerShell 5.1 reads BOM-less files as ANSI, so a UTF-8 character such as an em dash is decoded into bytes that include a `"`, which closes a string literal early and breaks parsing.

---

## Quick start and checks

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
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8000
```
Organizer portal available at: `http://localhost:8000/login`

Student web sign-in uses Firebase Authentication at `/student/auth`, which also handles email verification and password recovery. Laravel verifies Firebase ID tokens and accepts only verified `@student.mseuf.edu.ph` identities. Configure the Firebase web settings and authorized Render domain as described in [the deployment guide](docs/deployment.md). Resend is optional and is used for event-manager invitations; unsafe or missing mail settings make invitation requests fail closed.

### 3. Run Backend Integration Tests
The fast backend suite runs on in-memory SQLite:
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

The PostgreSQL verifier checks concurrency guarantees against the real server using genuinely overlapping
transactions released through a two-process barrier. Core cases include:

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
7. a staff authorization reassignment held open while a scan is in flight — the scan blocks
   on the authorization row under `FOR UPDATE` and is then rejected, proving stale authorization
   cannot race through admission
8. cloud and node runtimes racing to bind the same database — exactly one runtime is accepted
9. a registration waiting on a held cutoff — registration observes the cutoff and is rejected
10. concurrent preparation requests return one snapshot version
11. snapshot preparation waits for an event and gate update and captures the committed data
12. concurrent receipt of one snapshot produces one staged record and the same receipt
13. conflicting packages for one event version cannot both be staged
14. concurrent registration of one node issues only one secret
15. concurrent exports bind a snapshot to only one target node
16. reuse of one snapshot ID across events produces one staged record and a handled conflict
17. concurrent imports of one snapshot produce one node event
18. concurrent snapshot refreshes retain one node event
19. a refresh waits for an in-flight admission and preserves it
20. a staff login waits for credential rotation and rejects the old password
```bash
cd api
php scripts/verify_pgsql_concurrency.php
```

Cases 4, 5, and 7 hold an uncommitted conflicting write from a second connection, wait
until PostgreSQL reports the worker blocked (`pg_stat_activity`), then release it.
The script provisions its own `ingress_concurrency` database (created only when the name does not already exist)
so demonstration data is never touched, and exits non-zero if any invariant is
violated. Set `INGRESS_CONCURRENCY_DB` to an unused database name for each run. The verifier refuses to reset an existing database. It explicitly uses PostgreSQL with the `DB_HOST`, `DB_PORT`, `DB_USERNAME`, and `DB_PASSWORD` settings and ignores `DB_URL`.

#### Mobile checks

Run the mobile lint, type, session, scanner-route, and build-configuration checks from the app directory:

```bash
cd mobile
npx expo lint
npx tsc --noEmit
node tests/session.test.mjs
node --test tests/scanner-only.test.mjs tests/build-config.test.mjs
```

### 4. Staff scanner app (Expo React Native)

```bash
cd mobile
npm ci
npx expo start
```
- The app contains only Event Node staff login, pairing, assigned events and gates, scan preview and confirmation, and walk-in flows. It requires HTTPS and the venue LAN.
- `preview` and `production` use Android system certificate trust. For an isolated venue demo with a private certificate, configure the exact Node host and public CA for the separate `lanDemo` profile. See [Staff scanner build instructions](mobile/README.md).

---

### 5. Standalone Android APK build (EAS)

To produce an installable standalone APK for physical Android devices that runs independently without Expo Go or the Metro development server:

```bash
cd mobile
npx eas-cli@latest login
npx eas-cli@latest build --platform android --profile preview
```

**Important Operational Notes:**
- **Build Creation**: EAS Build compiles the native Android package in the cloud, so an internet connection is required while generating and downloading the APK.
- **No Expo Go or Metro Dependency**: The resulting `.apk` embeds the JavaScript bundle. Once installed, it launches directly on Android without requiring Expo Go or a running Metro bundler.
- **Cloud API**: The staff scanner has no Cloud API URL or attendee account session.
- **Event Node access**: Staff pair with the local Event Node over venue Wi-Fi. The node must use a certificate trusted by the selected Android profile. Use the separate `lanDemo` profile for a custom CA scoped to one exact Node host.
- **Transport**: HTTP is disabled in every build. The scanner rejects non-HTTPS Event Node addresses.

---
## Documentation

- [System Architecture Specification](docs/architecture.md)
- [Current architecture and runtime boundaries](docs/second-half-architecture.md)
- [Deployment and operations](docs/deployment.md)
- [Implementation Roadmap](docs/roadmap.md)
- [Physical-device and venue test plan](docs/physical-device-test-plan.md)
- [REST API Reference](docs/api.md)
- [Classroom LAN Defense Guide](docs/lan-demo.md)
- [Architecture Decision Records (ADRs)](docs/design-decisions.md)
- [Demo Data & Official MSEUF Sources](docs/demo-data.md)
