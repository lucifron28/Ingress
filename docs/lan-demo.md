# Ingress LAN Demonstration & Defense Operational Guide

**Manuel S. Enverga University Foundation (MSEUF)**  
*Event Ticketing, Admission, and Attendance Management System*

This guide provides concrete instructions for reproducing and defending the **current 50% prototype** during the capstone defense.

> **Milestone scope:** this guide intentionally documents the implementation that exists today, where both student and staff phones can be pointed at the local Event Node manually. The planned second-half architecture changes that split: students use the cloud and cached offline tickets, while only staff scanners connect to the Event Node, normally through QR pairing. See [Second-half target architecture](second-half-architecture.md).

---

## 1. Physical Demonstration Topology

```
                  Campus Wi-Fi Router (Local Access Point)
                     [No Internet Connection Required]
                                     │
           ┌─────────────────────────┼─────────────────────────┐
           │                         │                         │
     192.168.x.x               192.168.x.x               192.168.x.x
     [Developer Laptop]       [Staff Phone 1]           [Staff Phone 2]
     • Laravel 13 Server      • Entrance 1 Scanner      • Entrance 2 Scanner
     • PostgreSQL 18 DB
     • Blade Dashboard
```

> **Important Defense Note**: The developer's laptop simulates the central **Ingress Event Node** for this 50% prototype milestone. Do not present the laptop itself as the proposed production cloud architecture. In production, this role is fulfilled by an on-premises local Event Node server.

> **Transport security (demo scope)**: the entire procedure below uses plain **HTTP over a controlled local LAN or hotspot**. That is an accepted simplification for demonstrations, and it is also why the mobile build enables Android `usesCleartextTraffic` (configured via the `expo-build-properties` plugin) and iOS `NSAllowsArbitraryLoads`. It is **not** a production posture: passwords, Sanctum bearer tokens, QR credentials, and attendee identity data must all travel over **HTTPS/TLS** in production. See ADR 009 in `docs/design-decisions.md` for the requirements and the production checklist.

---

## 2. Pre-Demonstration Server Setup

### Step 2.1: Discover the Laptop's LAN IP Address
Run the provided discovery script or use `ipconfig`:
```powershell
powershell -ExecutionPolicy Bypass -File scripts\find-ip.ps1
```
This displays your active Wi-Fi IPv4 address (e.g., `192.168.55.128`) and the exact base URL to enter on the phones.

### Step 2.2: Ensure PostgreSQL 18 is Running
Run the database launcher:
```powershell
powershell -ExecutionPolicy Bypass -File scripts\start-db.ps1
```
Or start manually via `pg_ctl`:
```cmd
"C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe" -D "C:\Users\Ron\ingress_db" -l "C:\Users\Ron\ingress_db\server.log" start
```

### Step 2.3: Allow Ingress Port 8000 in Windows Firewall
Ensure inbound traffic on port 8000 is allowed so mobile phones on the Wi-Fi subnet can reach Laravel:
```powershell
New-NetFirewallRule -DisplayName "Ingress LAN Server" -Direction Inbound -LocalPort 8000 -Protocol TCP -Action Allow
```

### Step 2.4: Bind Laravel to All Network Interfaces (`0.0.0.0`)
```bash
cd api
php artisan serve --host=0.0.0.0 --port=8000
```
> **Warning**: Do NOT use `localhost` or `127.0.0.1` for the host parameter. You MUST bind to `0.0.0.0` for physical devices on the LAN to connect.

---

## 3. Pre-Configured Demo Accounts

These accounts exist **only while demo mode is enabled** (`INGRESS_DEMO_MODE=true`, which `api/.env.example` ships). `DatabaseSeeder` creates them, and the application refuses to boot with demo mode on while `APP_ENV=production`.

All demo accounts use the standard password: `password`. Outside demo mode there are no accounts at all, so provision the first organizer yourself:

```bash
cd api
php artisan tinker --execute="\App\Models\User::create(['name'=>'Event Administrator','email'=>'you@mseuf.edu.ph','role'=>'admin','password'=>bcrypt('a-real-password')]);"
```

| Role | Account Name | Email | Initial Mode |
| :--- | :--- | :--- | :--- |
| **Admin** | MSEUF Administrator | `admin@mseuf.edu.ph` | Web Management |
| **Organizer** | Prof. Juan Dela Cruz (CCS) | `organizer@mseuf.edu.ph` | Web Management |
| **Staff Entrance 1** | Staff Alex Rivera | `staff.gate_a@mseuf.edu.ph` | Mobile Scanner |
| **Staff Entrance 2** | Staff Bea Ramos | `staff.gate_b@mseuf.edu.ph` | Mobile Scanner |
| **Staff Entrance 3** | Staff Chris Dizon | `staff.gate_c@mseuf.edu.ph` | Mobile Scanner |
| **Student 1 (Primary)** | Ron Vincent Cada (`A23-35524`)| `a23-35524@student.mseuf.edu.ph` | Mobile Attendee |
| **Student 2** | Patricia Reyes (`2024-01002`)| `student2@mseuf.edu.ph` | Mobile Attendee |
| **Student 3** | Mark Aquino (`2024-01003`) | `student3@mseuf.edu.ph` | Mobile Attendee |

The mobile quick-select chips and the pre-filled password appear only when the app is built with `EXPO_PUBLIC_INGRESS_DEMO_MODE=true`.

---

## 4. Mobile Client Connection Guide

> Changing the Event Node here signs you out on purpose. A Sanctum token is only meaningful to the server that issued it, so the app clears the stored token and cached identity before saving the new address and returns you to the login screen.

1. Ensure all testing phones are connected to the **same Wi-Fi network** as the laptop.
2. Launch the mobile app:
   - **Standalone APK (Recommended)**: Open the installed **Ingress** app directly on the phone (does not require Expo Go or Metro).
   - **Development Mode**: Run `cd mobile && npx expo start` and open via Expo Go or development client.
3. On the login screen of each phone, tap **Change Server IP**.
4. Enter the base URL using the laptop IP found in Step 2.1:
   ```
   http://<LAN_IP>:8000/api/v1
   ```
   Optionally, the app-wide starting value can be preset for a demo build via the
   `EXPO_PUBLIC_API_URL` environment variable instead of relying on a per-device
   default.
5. Tap **Test Connection**. The banner will display `SUCCESS: Connected to Ingress Event Server`.
6. Tap **Save URL**.

---

## 5. Ten-Step Classroom Defense Demonstration Script

Execute this exact scenario to prove all architectural guarantees to the evaluation panel:

### Step 1: Open Organizer Attendance View on Laptop
1. Open browser to `http://localhost:8000/login`.
2. Sign in as `organizer@mseuf.edu.ph` / `password`.
3. Click **Attendance** on **MSEUF University Intramurals 2026**.

### Step 2: Staff Phone 1 — Select Entrance 1
1. On Staff Phone 1, tap demo button: **Staff Alex Rivera (Entrance 1)**.
2. Sign in.
3. Select **Entrance 1** &rarr; **Scan**.
4. Viewfinder opens with green reticle and gate indicator: `GATE: Entrance 1`.

### Step 3: Staff Phone 2 — Select Entrance 2
1. On Staff Phone 2, tap demo button: **Staff Bea Ramos (Entrance 2)**.
2. Sign in.
3. Select **Entrance 2** &rarr; **Scan**.
4. Viewfinder opens with gate indicator: `GATE: Entrance 2`.
### Step 4: Student Phone — Display Digital QR Ticket
1. On Student Phone (or browser tab), sign in as `a23-35524@student.mseuf.edu.ph` (or tap **Ron Vincent Cada** demo chip).
2. Navigate to **Discover** &rarr; Select **MSEUF University Intramurals 2026**.
3. Tap **Register for Event**.
4. Tap **Show Digital Ticket**.
5. Display the high-contrast opaque QR ticket.

### Step 5: Admit Ticket at Entrance 1
1. Point Staff Phone 1 (Entrance 1) at Ron Vincent Cada's QR code.
2. **Result**: Staff Phone 1 displays a read-only ticket preview:
   ```
   [✓] VALID TICKET
   Ron Vincent Cada (A23-35524)
   ```
3. Staff verifies the attendee identity and taps **CONFIRM ADMISSION**.
4. **Result**: Staff Phone 1 records the transactional admission:
   ```
   [✓] Ticket Valid
   Admitted at 1:42 PM
   Attendee: Ron Vincent Cada (A23-35524)
   ```
5. Look at the laptop organizer dashboard: The **Checked In** count increments to 1, and the recent admissions feed displays Ron Vincent Cada admitted at Entrance 1.

### Step 6: Anti-Passback Test — Attempt Admission at Entrance 2
1. Immediately take Student Phone's QR code to Staff Phone 2 (Entrance 2).
2. Point Staff Phone 2 at the exact same QR code.
3. **Result**: Staff Phone 2 immediately rejects entry with:
   ```
   [!] Already Checked In
   Previously admitted at Entrance 1, 1:42 PM
   Attendee: Ron Vincent Cada (A23-35524)
   ```
4. Verify the organizer attendance view: No second admission row is created.

### Step 7: Offline Demonstration (Disconnect Internet)
1. On the laptop, unplug the ethernet WAN cable or disconnect the Wi-Fi router from the internet **while keeping the local Wi-Fi router on**.
2. Ping `google.com` on laptop &rarr; Fails (no internet).

### Step 8: Admit Another Student Without Internet
1. On Student Phone 2, sign in as `student2@mseuf.edu.ph` and open their ticket.
2. Scan ticket at Entrance 2.
4. The organizer dashboard immediately updates over the local network.

### Step 9: Fail-Closed Security Test
1. On Staff Phone 1, turn off Wi-Fi (disconnect from LAN).
2. Scan any QR code.
3. **Result**: The app immediately halts scanning and displays:
   ```
   [⚡] Server Unavailable
   Reconnect to the event network before scanning.
   ```
4. This proves Ingress **fails closed** and never makes optimistic client-side guesses offline.

### Step 10: Concurrency Race Condition Proof
The PHPUnit suite runs on in-memory SQLite, which serialises writers and therefore **cannot** demonstrate a race. Prove the concurrency guarantees against real PostgreSQL instead:

```bash
cd api
php scripts/verify_pgsql_concurrency.php
```

It creates its own `ingress_concurrency` database, releases transactions through a two-process barrier, and asserts sixteen invariants. If that database already exists, set `INGRESS_CONCURRENCY_DB` to an unused name; the script refuses to reset an existing database:

| Case | Invariant |
| :--- | :--- |
| A | two simultaneous registrations for the final seat → exactly one confirmed registration |
| B | two gates scanning the same ticket → exactly one admission; the other reports `already_admitted` |
| C | one `client_scan_id` replayed across two tickets → exactly one admission; the other is an idempotency conflict |
| D | a worker forced to collide on the unique index → mapped to `idempotency_conflict`, never an unhandled 500 |
| E | an event cancellation held open while a scan is in flight → the scan blocks, then is rejected; no admission recorded |
| F | two different tickets scanned while a shared event lock is held → both admitted, proving scanners are not serialised |
| G | a staff authorization reassignment held open while a scan is in flight → the scan blocks on the authorization row, then is rejected; no admission recorded |
| H | cloud and node race to bind one database → exactly one runtime succeeds |
| I | a registration waits on an uncommitted cutoff → it sees the cutoff and is rejected |
| J | concurrent snapshot preparation → both requests return the same version |
| K | snapshot preparation waits on an event and gate update → it captures the committed data |
| L | repeated concurrent receipt → one staged snapshot and the same receipt |
| M | conflicting packages for one event version → exactly one is staged |
| N | concurrent node registration → one secret is issued |
| O | concurrent exports to two nodes → one target is bound |
| P | reuse of a snapshot ID across events → one staged record and a handled conflict |
The script exits non-zero if any invariant fails.

---

## 6. Troubleshooting LAN Issues

| Symptom | Probable Cause | Resolution |
| :--- | :--- | :--- |
| `SERVER UNAVAILABLE` | Phone and laptop on different Wi-Fi networks | Verify both devices show the same Wi-Fi SSID |
| Phone connection times out | Windows Firewall blocking port 8000 | Run PowerShell firewall rule from Step 2.3 |
| Laravel unreachable from LAN | Server started with `127.0.0.1` instead of `0.0.0.0` | Stop server and run `php artisan serve --host=0.0.0.0 --port=8000` |
| IP changed after reconnecting | DHCP lease refreshed | Run `ipconfig` again and update the IP in mobile settings |
