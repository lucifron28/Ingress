# Ingress Architecture Decision Records (ADRs)

**Manuel S. Enverga University Foundation (MSEUF)**  
*Project Design Rationale & Technical Tradeoffs*

---

## ADR 001: Monolithic Laravel 12 Backend with Blade & Sanctum REST API

### Context
Ingress requires an organizer web application for event management, reporting, and staff assignment, alongside an API layer serving cross-platform mobile apps for students and gate staff.

### Alternatives Considered
1. **Full SPA (Next.js / Vue) + Separate Laravel API**: Adds unnecessary build complexity, state synchronization overhead, and deployment friction for a student project.
2. **Microservices (Go scanner service, Node auth service, Python analytics)**: Violates project requirements; introduces distributed network failures and impossible defense justification.
3. **Monolithic Laravel with Blade Web UI + Sanctum REST API**: Single codebase, shared database transactions, native session management for organizers, and lightweight bearer token issuance for mobile clients.

### Decision
Use **Laravel 12** as a cohesive monolith. Blade templates provide fast, robust server-rendered administration for campus organizers, while Laravel Sanctum provides token authentication for mobile clients under `/api/v1/*`.

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
- The database stores `qr_hash = hash('sha256', $secret)` (one-way cryptographic hash).
- Zero student PII is exposed in the QR code.
- Verification uses constant-time string comparison (`hash_equals`) to prevent timing side-channel attacks.

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
Use **Expo SDK with Expo Router v4** and **expo-secure-store**. Pinned to a minimal package footprint: `expo-router`, `expo-secure-store`, `expo-camera`, and `react-native-qrcode-svg`.

---

## ADR 007: MSEUF Visual Identity & Accessible Color Architecture

### Context
Ingress must reflect Manuel S. Enverga University Foundation branding while maintaining high contrast and avoiding communication of status by color alone.

### Decision
Establish the Ingress UI color system based on official MSEUF brand guidelines:
- **Primary Maroon**: `#800000` (10.94:1 AAA contrast on white).
- **Secondary Maroon**: `#960000` (official vector logo maroon).
- **Accent Gold**: `#C5A059` / `#D4AF37` (harmonious academic accent).
- **Surface**: `#FFFFFF` pure white cards.
- **Neutrals**: `#0F172A` (headings), `#334155` (body), `#E2E8F0` (borders).
- **Strict Accessibility Rule**: Scanners must never use color alone. Explicit titles (`Ticket Valid`, `Already Checked In`, `Invalid Ticket`, `Server Unavailable`), timestamps, and symbols (`[✓]`, `[!]`, `[✕]`) are mandatory.

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
