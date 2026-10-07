# Ingress Architecture Decision Records (ADRs)

**Manuel S. Enverga University Foundation (MSEUF)**  
*Project Design Rationale & Technical Tradeoffs*

---

## ADR 001: Monolithic Laravel 13 Backend with Blade & Sanctum REST API

### Context
Ingress requires an organizer web application for event management, reporting, and staff assignment, alongside an API layer serving cross-platform mobile apps for students and gate staff.

### Alternatives Considered
1. **Full SPA (Next.js / Vue) + Separate Laravel API**: Adds unnecessary build complexity, state synchronization overhead, and deployment friction for a student project.
2. **Microservices (Go scanner service, Node auth service, Python analytics)**: Violates project requirements; introduces distributed network failures and impossible defense justification.
3. **Monolithic Laravel with Blade Web UI + Sanctum REST API**: Single codebase, shared database transactions, native session management for organizers, and lightweight bearer token issuance for mobile clients.

### Decision
Use **Laravel 13** as a cohesive monolith. Blade templates provide fast, robust server-rendered administration for campus organizers, while Laravel Sanctum provides token authentication for mobile clients under `/api/v1/*`.

---

## ADR 002: PostgreSQL 18 with Pessimistic Row Locking for Capacity Invariants

### Context
Event capacity is a hard physical invariant ($confirmed \le capacity$). Under high concurrency (e.g. hundreds of students attempting to claim the final seats when registration opens), race conditions can cause overbooking.

### Alternatives Considered
1. **Application-level read-then-write check**:
   ```php
   if (Registration::count() < $capacity) {
       Registration::create([...]);
   }
   ```
   *Vulnerable to race conditions*: Multiple threads can read `count == 49`, pass the condition simultaneously, and insert 51 registrations.
2. **Optimistic Locking (`version` column)**:
   Causes high transaction rollbacks and poor user experience under high contention.
3. **Redis atomic decrement**:
   Introduces an additional stateful service dependency that can fall out of sync with relational database state during power failures.
4. **Pessimistic Row-Level Locking (`SELECT ... FOR UPDATE`)**:
   PostgreSQL native write-lock on the event row during the transaction.

### Decision
Use **PostgreSQL 18** with `DB::transaction()` and Eloquent's `lockForUpdate()` on the `Event` row. The database strictly serializes concurrent attempts for the final seat. Exactly one transaction succeeds; all subsequent transactions see `confirmed == capacity` and fail gracefully.

---

## ADR 003: Opaque QR Credential Design with SHA-256 Hash Storage

### Context
QR tickets are displayed on attendee mobile screens and scanned at physical gates. Encoding student identity directly into the QR creates severe security and privacy vulnerabilities.

### Alternatives Considered
1. **Cleartext JSON inside QR (`{ "student_id": "2024-00123", "name": "Carlo" }`)**:
   Fatal privacy violation. Anyone with a standard phone camera can read student PII.
2. **Signed JWT inside QR**:
   While tamper-proof, asymmetric offline validation cannot prevent double-entry passback across gates without centralized real-time state.
3. **Opaque Token (`ingress:v1:<public_id>:<random_secret>`)**:
   QR contains only an opaque reference and a 256-bit cryptographically secure pseudorandom secret.

### Decision
Adopt the **Opaque Token** pattern:
- The QR payload contains `ingress:v1:<ticket_public_id>:<secret>`.
- The database stores two views of the secret: `qr_hash = hash('sha256', $secret)`, which is one-way and used for verification, and `encrypted_secret = Crypt::encryptString($secret)`, which the application can decrypt with `APP_KEY` so the owning attendee can redisplay their QR.
- Zero student PII is exposed in the QR code.
- Verification uses constant-time string comparison (`hash_equals`) to prevent timing side-channel attacks.

### Consequences
Storage is therefore **not** purely one-way. A database dump alone does not reveal plaintext QR secrets, but a dump combined with `APP_KEY` does — so `APP_KEY` must be treated as a production secret that never shares a backup with the database. The `encrypted_secret` column exists because a one-way hash alone would make a ticket unrecoverable for an attendee who reinstalled the app.

---

## ADR 003a: QR Secret Threat Model

### Context
Storing a recoverable copy of the QR secret alongside a one-way hash needs an explicit threat model. The tempting description "the secret is only stored hashed" is not true and must not appear in this documentation.

### Decision
State the real model rather than a flattering one:

| Compromise | Consequence |
| :--- | :--- |
| Database only | `qr_hash` is one-way and `encrypted_secret` is unreadable without `APP_KEY`, so plaintext QR secrets are **not** directly revealed. |
| Database **and** `APP_KEY` | `encrypted_secret` decrypts, so a working QR can be reconstructed. |

Operational rules that follow: `APP_KEY` lives only in the server environment; it is never committed, never shipped to the mobile app, and never stored in the same backup as a database dump. Rotating it invalidates every existing `encrypted_secret`, so it is a long-lived production secret.

---

## ADR 004: Server-Authoritative Scanner & Fail-Closed Security

### Context
In multi-gate physical events (e.g. Gate A and Gate B at the MSEUF Gymnasium), ticket passback via messaging apps (sending screenshots of QR tickets) is the primary vulnerability.

### Alternatives Considered
1. **Client-Authoritative Offline Scanning**:
   Scanners cache valid tickets locally and admit offline.
   *Fatal vulnerability*: A ticket screenshot scanned simultaneously at Gate A and Gate B will be granted entry at both locations because local caches are disconnected.
2. **Server-Authoritative LAN Validation**:
   Scanners act strictly as capture peripherals. Every scan is validated in real time against the central Ingress server over the campus Wi-Fi.

### Decision
Implement **Server-Authoritative LAN Validation**. Scanners never determine validity independently. If a staff phone loses connection to the local server, it **fails closed** (`SERVER UNAVAILABLE`). No optimistic or unverified admissions are permitted.

---

## ADR 005: Client-Generated UUID Idempotency (`client_scan_id`)

### Context
In wireless LAN environments with packet loss or retries, a scanner might send a scan request, receive no response due to Wi-Fi jitter, and re-transmit the request.

### Alternatives Considered
1. **Blind Retries**:
   Can cause the second request to be flagged as a duplicate admission ("Already Checked In").
2. **Client-Generated Scan UUID**:
   The mobile app generates a UUID v4 `client_scan_id` when the camera detects a barcode, before making the network request.

### Decision
Enforce a `UNIQUE(client_scan_id)` constraint on the `admissions` table. If a network retry occurs with the same scan ID, the server recognizes the idempotent replay and returns the original `Ticket Valid` response without creating duplicate records or falsely triggering anti-passback.

A replay is only honoured when it matches the original request on **all** of `ticket_id`, `credential_version`, `access_point_id`, and event. Anything else returns HTTP 409 `idempotency_conflict`. The `credential_version` term matters because tickets support cryptographic credential versioning and controlled rotation; without it, a scan ID minted against a retired issuance could be accepted as a retry for a rotated credential.

A concurrent insert that loses the race on the unique index is caught and mapped to the same 409 rather than surfacing as an unhandled database error.

---

## ADR 006: Expo SDK 57 with File-Based Expo Router & SecureStore

### Context
The mobile app must support cross-platform deployment (Android and iOS) on campus with camera barcode scanning and secure credential persistence.

### Alternatives Considered
1. **Bare React Native**:
   High configuration overhead, complex native toolchain maintenance, and fragile native module linking.
2. **Expo with standard AsyncStorage**:
   AsyncStorage stores Sanctum bearer tokens in plaintext unencrypted XML/SQLite files on device storage.
3. **Expo SDK with Expo Router & expo-secure-store**:
   File-based route navigation, native hardware keystore encryption (Android Keystore / iOS Keychain), and managed `expo-camera` barcode scanning.

### Decision
Use **Expo SDK 57 with Expo Router 57.x** and **expo-secure-store**. Pinned to a minimal package footprint: `expo-router`, `expo-secure-store`, `expo-camera`, and `react-native-qrcode-svg`. Expo Router now follows Expo SDK version numbering rather than the earlier standalone `v4` line.

---

## ADR 007: MSEUF Visual Identity & Accessible Color Architecture

### Context
Ingress must feel related to Manuel S. Enverga University Foundation while maintaining high contrast and avoiding communication of status by color alone. MSEUF's established institutional identity is maroon and white. No official digital brand specification (exact hex values, CMYK, or Pantone numbers) for those colours is published in a form this project could cite, so Ingress does not claim to reproduce official institutional colour values.

### Decision
Ingress uses a maroon-and-white interface **inspired by** MSEUF's established visual identity. The following are **Ingress UI design tokens**, not official MSEUF brand values:
- **Primary Maroon**: `#800000` (10.94:1 AAA contrast on white).
- **Secondary Maroon**: `#960000` (used for vector/logo-adjacent fills).
- **Accent Gold**: `#C5A059` / `#D4AF37` (harmonious academic accent; a supporting tone, never a primary brand colour).
- **Surface**: `#FFFFFF` pure white cards.
- **Neutrals**: `#0F172A` (headings), `#334155` (body), `#E2E8F0` (borders).
- **Strict Accessibility Rule**: Scanners must never use color alone. Explicit titles (`Ticket Valid`, `Already Checked In`, `Invalid Ticket`, `Server Unavailable`), timestamps, and symbols (`[✓]`, `[!]`, `[✕]`) are mandatory.

No official MSEUF logo artwork or seal is bundled with this repository. The institution is referenced only by the wordmark text "MSEUF" and by the footer's full university name, so no third-party asset usage rights are assumed by the project.

### The Ingress mark

Ingress ships its own product mark — a maroon gate/turnstile monogram with a separate `INGRESS` wordmark — as `ingress_logo.png` in the repository root. It is an Ingress asset, not an MSEUF asset, and it does not replace or imitate the university seal.

Measured properties of the mark, which drive how it may be placed:

| Property | Value |
| :--- | :--- |
| Canvas / format | 1536 × 1024 PNG, transparent background (75.7 % fully transparent) |
| Opaque core colour | `#780417` → `#B40420` (a crimson-maroon, **not** the UI token `#800000`) |
| Lockup aspect | 1.46 (stacked: monogram above wordmark); monogram alone is 1.30 |
| Contrast on `#FFFFFF` | **10.53:1** — passes AAA |
| Contrast on `#800000` | **1.03:1** — effectively invisible |
| Contrast on `#0F172A` | **1.66:1** — fails |

**Constraint:** the mark is a dark-on-light device. It must be placed on white or another light surface. On the maroon header it is enclosed in a white plate (the same treatment the previous `MSEUF` text badge used), and on the login card it sits directly on the white card.

Derivatives are generated from the master rather than hand-edited, so the master stays the single source of truth:

| Derived asset | Purpose |
| :--- | :--- |
| `api/public/images/ingress-logo.png` | Full lockup for the organizer login card and the README |
| `api/public/images/ingress-mark.png` | Monogram for the maroon header plate |
| `api/public/images/ingress-favicon.png` | Browser favicon (monogram on white) |
| `mobile/assets/icon.png` | iOS/Android app icon (monogram on white, flattened to RGB because iOS rejects icons with an alpha channel) |
| `mobile/assets/android-icon-foreground.png` | Adaptive icon foreground (monogram inside the 66 % safe zone) |
| `mobile/assets/android-icon-background.png` | Adaptive icon background (white — was `#800000`, which made the monogram unreadable) |
| `mobile/assets/android-icon-monochrome.png` | Themed-icon silhouette; Android tints the alpha channel |
| `mobile/assets/favicon.png` | Expo web favicon |

---

## ADR 008: Deferred Cloud Sync & PostgreSQL Replication

### Context
Future versions of Ingress may introduce cloud synchronization between an online Ingress cloud instance and local campus Ingress Event Nodes.

### Decision
Explicitly out of scope for the 50% milestone:
- No PostgreSQL logical replication.
- No cloud-to-edge synchronization frameworks.
- No Kafka or complex message brokers.
- The 50% milestone focuses exclusively on rock-solid, concurrency-safe, anti-passback event execution on a single local authoritative source of truth.

---

## ADR 009: Transport Security — LAN HTTP vs Production HTTPS

### Context
The prototype runs the Event Node on a laptop and phones connect over a local Wi-Fi network or hotspot. Configuring TLS for that setup would add certificate distribution to every device and slow down the one thing the milestone needs to demonstrate.

### Decision

**Prototype / demo (this milestone).** Plain HTTP over a controlled local LAN or hotspot is accepted so that a classroom demonstration needs no PKI. The mobile build makes this possible through Android `usesCleartextTraffic` (configured via the `expo-build-properties` plugin) and iOS `NSAllowsArbitraryLoads` + `NSAllowsLocalNetworking` in `mobile/app.json`. Those flags are a **demo affordance**, not a deployment posture.

**Production.** Event Node traffic must use HTTPS/TLS. Passwords, Sanctum bearer tokens, QR credentials, and attendee identity data all cross this link, and over plaintext HTTP on an untrusted network they are readable and modifiable in transit. A production deployment must:

- terminate TLS in front of Laravel (reverse proxy or the platform's load balancer) and never expose the cleartext listener;
- remove the cleartext allowances from `mobile/app.json` and pin the Event Node to an `https://` origin;
- set `SESSION_SECURE_COOKIE=true` and `APP_URL=https://…`.

### Consequences
The cleartext allowances and the HTTP LAN procedure in `docs/lan-demo.md` are scoped to demonstration use and are called out as such. No certificate authority, pinning, or client-certificate distribution is implemented in this milestone; a production rollout would also have to decide how an Event Node obtains a certificate on a network with no outbound internet.

---

## ADR 010: Explicit Demo Mode Instead of Always-On Demo Credentials

### Context
The seeder created a fixed set of accounts — student, staff, organizer, admin — all sharing the password `password`, and the mobile login screen advertised them and pre-filled that password. That makes a fresh clone immediately demonstrable, but it also means that deploying the application without changes publishes a set of accounts anyone can sign into.

### Alternatives Considered
1. **Keep demo accounts unconditional.** Simplest, and unacceptable: the default deployment ships predictable credentials.
2. **Delete the demo accounts entirely.** Safe, but makes both the classroom demonstration and the student's own setup markedly harder.
3. **Gate them behind one explicit flag.** Demo remains one environment variable away, while the default posture creates no predictable credential.

### Decision
Introduce `INGRESS_DEMO_MODE`, read once into `config('app.demo_mode')` with a **default of `false`**:

- `DatabaseSeeder` seeds the demonstration users and events only when demo mode is on. Venues (pure reference data with nothing predictable) are always seeded.
- The organizer login page advertises the demo credentials only when demo mode is on.
- The mobile app gates its quick-select chips and pre-filled password behind its own build-time flag, `EXPO_PUBLIC_INGRESS_DEMO_MODE`, which also defaults to off.
- Booting with demo mode enabled while `APP_ENV=production` is a **hard error**: the application refuses to start rather than serve predictable credentials.

`.env.example` ships `INGRESS_DEMO_MODE=true` so copying the example file keeps the documented quickstart working, while an environment that does not opt in gets nothing predictable.

### Consequences
Outside demo mode there are no accounts at all, so an operator must provision the first organizer deliberately; the README documents that one-liner. The mobile flag is client-side UX only and cannot make the server safer or less safe — whether the demo accounts exist is decided solely by the server flag.


---

## ADR 011: Hybrid Cloud Ticketing and Local Event-Day Admission

### Status
Planned for the second half. The 50% milestone still runs student and scanner traffic against one local server.

### Context
Students need normal internet-based event discovery, registration, account access, and ticket recovery, while event-day scanning must continue when public internet is weak or unavailable. Letting both the cloud and a disconnected Event Node independently mutate ticket validity would create split-brain admission state.

### Decision
Use two operational authorities separated by event phase:

- before finalization, the cloud is authoritative for student authentication, registration, ticket issuance, and transfer;
- at finalization, registration closes and the Event Node receives a verified final event snapshot;
- during admission, the Event Node is authoritative for ticket validation, anti-passback, walk-ins, and local exceptional actions;
- after the event, Event Node admissions and walk-ins reconcile back to the cloud.

The public internet is not part of the critical admission path.

---

## ADR 012: Registration Deadline, Finalization, and Walk-Ins

### Status
Planned for the second half.

### Context
Events commonly accept walk-ins. Continuing cloud registration after an Event Node has gone offline would allow the cloud to issue tickets the Event Node has never received.

### Decision
Every event has a registration deadline followed by an explicit **Prepare for Admission / Finalize Admission** step.

Finalization closes ordinary online registration and self-service transfer, then synchronizes a final preregistered attendee/ticket snapshot to the Event Node. The Event Node records a snapshot/version before admission begins.

After finalization, a person without a preregistered ticket is handled as a walk-in even if that person still has internet access.

Walk-ins are first-class registrations with `registration_source = walk_in`. The Event Node checks remaining capacity transactionally, creates the local registration/ticket, and can issue a printed pass containing an opaque QR and human-readable fallback code. Printing does not admit the attendee by itself; normal admission happens when the pass is scanned unless an authorized Register & Admit workflow is explicitly used.

---

## ADR 013: Keep Static Opaque Credentials, Do Not Add TOTP

### Status
Planned design decision. The current implementation already uses static opaque credentials.

### Context
A rotating TOTP QR was considered to reduce the useful lifetime of screenshots. It would require clock-window handling, ticket-secret synchronization, rotating QR state, and separate behavior for printed walk-in passes. It also would not prevent someone from forwarding a currently valid QR and having another person use it first.

### Decision
Keep one credential model for preregistered and walk-in tickets:

```text
ingress:v1:<ticket_public_id>:<random_secret>
```

The Event Node remains the security authority. It verifies the opaque credential, checks event/ticket state, and atomically consumes the ticket once across all entrances.

Ingress will not claim that static QR prevents sharing. Screenshot misuse is addressed by one-time server-authoritative consumption plus identity verification when the event requires it.

---

## ADR 014: Identity-Linked Tickets and Configurable Verification

### Status
Planned for the second half.

### Context
Possession of a valid QR proves possession of a ticket credential, not necessarily the identity of the person presenting it.

### Decision
Every ticket is linked to a current attendee identity. After a valid scan, the scanner may show the identity fields required by the event policy.

Events may choose:

1. **Standard**: valid credential plus attendee name and institutional/student identifier.
2. **Identity check**: Standard plus staff confirmation against approved identification.
3. **Enhanced**: Identity check plus an attendee photo when the event has a justified need for it.

Photo capture is optional and may also be used for walk-ins. Events collecting photos must define access, retention, and deletion rules rather than storing photos indefinitely.

The QR payload itself remains free of PII.

---

## ADR 015: Cloud Student Auth with Offline Session Restoration

### Status
Planned for the second half.

### Context
Students may arrive without usable internet but still need to open the app and show a ticket. Verifying passwords locally would create a second authentication system and would not reflect current server account state.

### Decision
Student authentication is cloud based. A device must authenticate successfully online before it may restore that student's session offline.

After a successful online session, the app securely caches the minimum identity and ticket material needed to render owned tickets. When the cloud is unreachable, a previously authenticated student may enter a read-only offline mode to view cached tickets and event information.

Offline mode cannot register, transfer, change credentials, or retrieve unsynchronized tickets.

A confirmed `401 Unauthorized` invalidates the local session and sensitive ticket cache. A transport failure does not automatically log out the student. Manual logout clears sensitive cached ticket credentials.

Students never configure an Event Node URL.

---

## ADR 016: Scanner QR Pairing and Event-Local Staff Authentication

### Status
Planned for the second half.

### Context
Manual IP entry is acceptable for the prototype but is poor event-day UX. Scanner authentication also cannot depend only on public internet if the system is intended to operate during an upstream outage.

### Decision
The Event Node provides a **Pair Scanner** QR containing a short-lived setup payload such as node identity, local URL, one-time pairing token, expiration, and optionally a certificate fingerprint. It must not contain a permanent administrator credential.

Manual Event Node address entry remains available only to staff/admin users as an advanced fallback.

Before finalization, the Event Node receives the staff accounts, roles, gate assignments, and permissions required for the event. During event operation, staff authenticate against the Event Node or use a previously established Event Node session. Authorization remains server-side.

---

## ADR 017: Ticket Transfer Changes Ownership and Rotates Credentials

### Status
Planned for the second half.

### Context
Attendees commonly give away a ticket when they can no longer attend. Without an official transfer path, users are encouraged to forward QR screenshots, which preserves the wrong identity and creates avoidable admission disputes.

### Decision
Support event-configurable ticket transfer before admission finalization.

A transfer:

1. is requested by the current owner for a specific recipient;
2. remains pending until the recipient accepts;
3. changes the ticket's current owner atomically on acceptance;
4. rotates the opaque credential so the previous owner's saved QR stops working;
5. records transfer history for organizer audit.

Until the recipient accepts, the original owner keeps the ticket. Scanner staff see the current owner, not the full transfer history.

Events may disable transfer, limit the number of transfers, or require organizer approval. Self-service transfer closes when the event is finalized for admission. Walk-in passes do not require self-service transfer support.
---

## ADR 018: MSEUF-First Student Accounts with Guest and Alumni Email Tickets

### Status
Planned for the second half.

### Context
Ingress is primarily for MSEUF events. Verified students are recurring institutional users who benefit from a persistent account, ticket wallet, registration history, transfer, and future notifications. Alumni and external guests are occasional attendees and should not be forced through the same account-activation flow.

A single email-only model for everyone would make students repeatedly verify identity for every event and would weaken the link between a ticket and the university student record. A universal account requirement for outsiders would create unnecessary friction for invited guests, alumni, parents, partner-school visitors, and paid external attendees.

### Decision
Keep normal self-service accounts focused on verified MSEUF students. Student activation must match a preloaded institutional directory record and verify the institutional email before the server creates the student account.

External attendees may receive tickets through either:

- organizer invitation;
- verified guest purchase/payment;
- event-day walk-in registration.

Guest and alumni tickets do not require a MSEUF account. The system creates an attendee record for the event, issues the same opaque Ingress credential used elsewhere, and may deliver the ticket by email as a QR ticket or printable attachment.

Ticket source values may distinguish `student_registration`, `guest_purchase`, `organizer_invitation`, and `walk_in`. Reporting may also distinguish verified Envergans from alumni and other external guests.

The ticket source changes how the ticket is issued, not how it is validated. Student-wallet tickets, emailed guest tickets, and printed walk-in passes all use the Event Node's server-authoritative validation pipeline.

This decision also reinforces the choice not to use TOTP. A static opaque credential can be shown from a student app, email, or printed pass without requiring the recipient to run a synchronized dynamic-code client.

---

## ADR 019: Append-Only Entry, Exit, and Re-Entry Tracking

### Status
Planned for the second half.

### Context
The first-half admission model records one successful admission and rejects every later scan. That is correct for strict one-time admission, but it cannot answer whether someone left the venue and later returned, nor can it tell organizers how many people are currently inside.

### Decision
Evolve attendance into an append-only movement history with `ENTRY` and `EXIT` transitions. The current state is derived from the latest valid movement for the ticket or attendee.

State rules:

```text
OUTSIDE --ENTRY--> INSIDE
INSIDE  --EXIT----> OUTSIDE
OUTSIDE --ENTRY--> INSIDE   # permitted re-entry
```

- `ENTRY` while already `INSIDE` is rejected as duplicate/passback.
- `EXIT` while already `OUTSIDE` is rejected as an invalid transition.
- A later `ENTRY` after an `EXIT` is allowed only when the event's re-entry policy permits it.
- Re-entry does not consume another capacity slot.
- Each movement records the ticket, access point, staff member, timestamp, direction, and idempotency identifier.
- The server must lock the ticket or equivalent presence state during a transition so two access points cannot create contradictory movements.

The existing two-stage scanner invariant remains in force: scanning performs read-only validation and identity preview; only explicit authorized staff confirmation records the entry or exit.

This enables dashboards to report total attendees who ever entered, currently inside, currently outside after a prior entry, re-entry count, and current Envergan-versus-external occupancy.

---
