# Physical-device and venue test plan

**Status:** Prepared; field testing has not been run. This plan is for an isolated staging deployment and synthetic attendee data. It does not establish production readiness or prove physical-device performance.

Use this plan after the required CI checks pass on the commit being tested. Record that commit, build ID, device details, venue network, and printer with the results. Do not put attendee names, email addresses, QR payloads, passwords, pairing codes, sync keys, or screenshots of live tickets in the evidence log.

## Stop conditions

Stop admission testing and keep the event closed if any of these occur:

- A second Event Node might still hold an active authority grant.
- A snapshot or authority receipt does not match the event, node, or version being tested.
- A scanner records an admission without staff confirmation, accepts an unauthorized gate, or creates duplicate movements after a retry.
- The cloud or Event Node TLS certificate is invalid or untrusted by a test device.
- An offline scanner appears able to admit without reaching the Event Node over the LAN.
- An admission, walk-in, or pending sync record is lost after a service restart.

For an Event Node failure after authority activation, stop all gates. Preserve the original node, database, grant, sync key, and logs. Do not activate a replacement or restore a clone while the original node might still admit. The application cannot fence an unreachable node or recover unsynchronized admissions from cloud data.

## 1. Prepare the staging deployment

1. Use the current Render cloud at `https://ingress-pp2u.onrender.com` and one Windows Event Node. Each runtime needs its own PostgreSQL 18 database and unique `APP_KEY`; never point the cloud and node at the same database. Do not reuse the legacy single-database demo. Safe legacy database migration is not implemented.
2. Configure the cloud with `INGRESS_RUNTIME=cloud`, `APP_DEBUG=false`, `INGRESS_DEMO_MODE=false`, a cloud-only authority private key, an exact CORS allowlist if needed, and the Firebase web settings. Resend is optional. If invitation mail is enabled, run `send_render_mail_test.ps1` with Resend's shared development sender to check static delivery to the Resend account owner's inbox.
3. Provision synthetic staging users through `provision_render_staging_account.ps1`. A synthetic student uses `@example.test` and an ID beginning `STAGE-`; its account remains unverified. Use the website to test public registration, ticket display, and PDF download. The synthetic student cannot exercise Firebase verification or verified-student ticket transfer. Do not mark a real student verified to bypass Firebase identity checks.
4. Configure one Windows Event Node using a single profile from [Deployment and Operations](deployment.md#configure-a-windows-event-node): Docker Desktop with WSL2, native WSL2 with Nginx, or native Windows IIS. Use a stable campus DNS name, reserved LAN address, and a publicly trusted certificate when possible. Android release builds do not trust user-installed CA roots by default. A public certificate issued with DNS-01 validation can use split-horizon DNS to resolve the node hostname to its LAN address on campus. Restrict the HTTPS listener to the staff subnet and keep PostgreSQL unreachable from attendee devices. For a venue demo that cannot use a public certificate, use the isolated host-scoped `lanDemo` profile documented in [Staff scanner build instructions](../mobile/README.md); test that profile on a physical device before relying on it.
5. For Docker, confirm the separate `ingress-scheduler` container is running; do not create a duplicate Windows scheduled task. For native WSL, confirm Linux cron is enabled and the Windows startup task starts the required services. For IIS, configure **Task Scheduler** to run Laravel's scheduler at startup and every minute. Confirm `/up` and the node API respond over HTTPS from staff Wi-Fi. After closing and reopening the mobile app, confirm the saved node token does not activate scanner access on its own. Tap **Continue as** to make a fresh `/me` request over the venue LAN; if the node is unavailable, scanner access stays inactive. Keep the venue LAN working when WAN is unavailable.
6. Before using real attendee information, obtain an institution-approved retention schedule and operator-owned deletion procedure. Automatic snapshot and roster retention is not implemented. Use synthetic records until that policy exists.
7. Prepare two synthetic events with two or more gates, staff assigned to specific gates, and a small capacity. Set one event to prohibit re-entry and the other to allow it. Enable the approved ID-check policy on one event. Finalize all policies before snapshot preparation. Prepare synthetic student, guest, alumni, and walk-in cases.

### Docker Desktop Event Node preflight

The following items are separate from CI and remain pending until someone runs them on the venue Windows host. Record the Docker Desktop version, Windows build, WSL distro, reviewed Git commit, and network details with the evidence:

- [ ] Docker Desktop's WSL2 engine and Ubuntu integration are enabled; `docker version` succeeds from Ubuntu. No second Docker Engine is installed inside WSL.
- [ ] The repository and file-backed secrets are on the WSL Linux filesystem. The secret directory is outside the repository and restricted to the operator's WSL account.
- [ ] `docker compose ... config --quiet` passes; the `ingress-migrate` service completes; `ingress-db` and `ingress-node` are healthy; and `ingress-scheduler` is running. Logs contain no credentials or private URLs.
- [ ] The PostgreSQL data volume survives a planned database-container recreation before event data is loaded. The node database has no host-published `5432` port.
- [ ] The Windows host's port 443 binding is limited to its reserved LAN IPv4 address and staff subnet. Port 8444 is reachable from the Windows host but not from a second LAN device.
- [ ] Windows resolves the operator hostname to loopback; staff phones resolve the same name to the reserved LAN address. The TLS name and chain are trusted by Windows and each Android phone.
- [ ] The first operator can sign in through `https://<node-hostname>:8444/node/operator`; scanner phones reach the API on HTTPS port 443.
- [ ] A planned Windows reboot starts Docker Desktop and the node database, web service, and separate scheduler without deleting the named volume. `/up` succeeds before scanners resume.
- [ ] With WAN disconnected and staff Wi-Fi intact, the Event Node remains available for admission. After WAN returns, the scheduler retries synchronization without duplicate records.
- [ ] A protected database backup is written outside the container and checkout, then copied to encrypted off-host storage. Restore is practiced only in an isolated test copy or after the original node is retired.

## 2. Build and install the Android staff scanner APK

The `preview` EAS profile creates an internal Android APK with system CA trust. The `lanDemo` profile creates an internal APK that also trusts a supplied public CA for one exact Event Node host. The `production` profile creates a store AAB and is not the profile for direct device installation. The scanner has no Cloud API URL or student-account configuration. All profiles require HTTPS for Event Node requests.

Build the default preview APK in PowerShell:

```powershell
cd mobile
npx eas-cli@latest login
npx eas-cli@latest build --platform android --profile preview
```

If the venue needs its own CA, add `INGRESS_LAN_DEMO_CA_CERT` as a **Sensitive File** and `INGRESS_LAN_DEMO_NODE_HOST` as a plain-text variable to the EAS **preview** environment, then build with `npx eas-cli@latest build --platform android --profile lanDemo`. The CA must be public, currently valid, and issued for the configured Event Node hostname or IP. See [Staff scanner build instructions](../mobile/README.md) for the certificate and host rules. Never add the CA private key to EAS or source control.

Download the APK from the EAS build page. With Android Platform Tools installed and USB debugging enabled, install it from PowerShell:

```powershell
adb devices
adb install -r .\Ingress-preview.apk
```

Repeat on each test phone. Confirm the EAS build commit and package ID `com.lucifron28.ingress`. In Android app settings, the scanner should need camera access; it should not need microphone, broad storage, or display-over-other-apps access. The first scanner visit should request camera permission. Denying it must leave QR scanning unavailable while staff can still use the manual local sign-in flow.

Before a future store build, use `npx eas-cli@latest build --platform android --profile production`. The profile produces an AAB for a Play testing track; it is not a sideloadable APK. See Expo's [APK build guide](https://docs.expo.dev/build-reference/apk/) for internal APK builds.

## 3. Test Firebase student sign-in, web registration, and tickets

1. If a Firebase test project and authorized `@student.mseuf.edu.ph` mailbox are available, create an account at `/student/auth`, complete Firebase email verification, sign in, and test password recovery. Confirm that a non-institutional address is rejected. Until Firebase email delivery is verified with an institutional account, mark this scenario pending; do not use the synthetic operator-provisioned student to bypass verification.
2. Register for the synthetic event on the website and confirm capacity decreases once. Open the ticket and confirm its QR remains a static opaque credential. Download the PDF. Do not photograph or share the QR.
3. On the test phone, open the website ticket and download its PDF. Confirm the saved PDF opens from local storage after the browser closes. Sign out in the website and confirm the previous account's ticket details are no longer visible there.
4. Ticket transfer requires verified student accounts. Test transfer through the website. If the institutional mailbox is not available, leave this scenario pending; do not use the synthetic unverified student to bypass it. Automated backend checks cover rejection of the sender's rotated QR.
5. Briefly disconnect WAN access while keeping the device on venue Wi-Fi. Confirm the previously downloaded PDF remains available to display, while online registration and transfer requests fail closed. Restore internet access for cloud setup; later repeat the WAN outage during LAN admission testing.

## 4. Finalize the event and activate one Event Node

1. Close registration from the cloud organizer page. Prepare the snapshot only after attendee, gate, staff, re-entry, and identity policies are final. Check the snapshot version and attendee count.
2. Register the intended node from a trusted Windows checkout with `powershell -ExecutionPolicy Bypass -File .\api\scripts\register_render_node.ps1`. Transfer its one-time node sync key to the node through a separate secure operator channel.
3. Transfer the encrypted snapshot through an approved secure channel. On the node, run `php artisan ingress:receive-snapshot PATH`, then return the staging receipt to the cloud organizer page.
4. On the node, run `php artisan ingress:import-snapshot CLOUD_EVENT_ID`, then return the import receipt. Confirm the cloud shows both receipts for the intended node and snapshot version.
5. Only after both receipts are accepted, download the signed authority grant and activate it on that node with `php artisan ingress:activate-event PATH`. Record the node ID and grant activation time. Do not issue another grant or activate a second node for this event.

If either receipt is missing or mismatched, do not activate authority. Before grant issuance, discard the provisional snapshot and restart the transfer with a correctly registered node. After grant issuance, follow the fail-closed stop procedure above if activation is uncertain.

## 5. Pair staff scanners on the venue LAN

1. On the node, run `php artisan ingress:list-node-staff` and verify each test staff account and gate assignment.
2. Provision a node-local staff credential with `php artisan ingress:provision-node-staff CLOUD_USER_ID`. Deliver the generated password privately.
3. Generate a one-use pairing QR on the node with `php artisan ingress:pair-node-staff CLOUD_USER_ID --url=https://NODE_HOST/api/v1`. Show it only to that staff member. It expires after three minutes; do not photograph or share it.
4. In the Android app, select **Scan pairing code**. Confirm the node ID and HTTPS address with the operator before accepting. Confirm the staff session works after closing and reopening the app while the phone remains on the venue LAN.
5. Repeat for a second staff member assigned to another gate. Confirm staff assigned to Gate A cannot operate Gate B. Try an expired or already-used pairing code and confirm it is rejected; issue a fresh code afterward.

## 6. Exercise offline admission across gates

1. Connect the Event Node and all test phones to the staff Wi-Fi. Confirm the stable node DNS name resolves to the reserved LAN address and its HTTPS certificate is trusted. Verify client isolation is off for the staff subnet and attendee devices cannot reach the node database.
2. Disconnect the router's WAN/internet connection while preserving the local Wi-Fi and Event Node. Confirm the staff scanner can still open the node event and a previously downloaded ticket PDF can still display its QR.
3. Scan a ticket and stop at the preview screen. Cancel or back out and confirm no movement was recorded.
4. Scan again and explicitly confirm entry. Scan the same ticket at the second gate; it must report already inside and create no second entry. Confirm the gate and staff shown in the response are correct.
5. Record an exit. On the event with re-entry disabled, confirm another entry is rejected. On the separate event with re-entry enabled, confirm a later entry succeeds after exit.
6. Try an invalid QR, a ticket for another event, and an unassigned or wrong-gate staff account. Each must fail without creating an admission. If the organizer has securely retained a synthetic sender QR from a pre-finalization transfer, confirm that the superseded credential is rejected after snapshot import.
7. Repeat the event's identity-check flow if enabled: preview remains read-only, the staff acknowledgment is required before confirmation, and the movement records the acknowledgment.

The internet may be unavailable during admission, but the venue LAN and the single authoritative Event Node must remain available. Scanners do not admit independently.

## 7. Test guest, alumni, walk-in, and printing flows

1. From the cloud organizer page, issue one guest and one alumni pass before registration cutoff. Open each printable pass and confirm it contains the correct synthetic identity and QR. Email delivery is not implemented; the organizer must hand each pass to its recipient through an approved private channel.
2. While WAN is available, create a synthetic accountless guest ticket through the public guest flow. Redeem it for Guest A, then transfer it to Guest B and Guest C using distinct synthetic identities. After each transfer, confirm the current guest can open the ticket and the prior redemption code no longer works. The automated backend test covers a lost response after commit; do not inject a session-storage failure on the live service.
3. With WAN still disconnected, issue a walk-in from the paired staff app. Confirm the node returns a pass, does not admit the person at issuance, and enforces the event capacity. Scan the pass, preview it, then explicitly confirm entry.
4. Test recovery with a synthetic walk-in attendee. Staff must verify the attendee in person under the event's approved identity procedure, select a recovery reason, and hand over the recovered pass directly. Confirm recovery does not admit the attendee. Do not record identity document numbers or keep copies.
5. Print a cloud guest/alumni pass from the organizer flow and a node walk-in pass from the mobile flow. Use a standard printer, verify the complete QR has a quiet margin, and scan the paper copy at normal gate distance and lighting. Record the printer model, paper size, scale, and scan result.
6. Keep every printed pass controlled; its QR is a bearer credential. Shred or securely destroy test copies after the run.

## 8. Restore internet and confirm synchronization

1. Reconnect WAN while keeping the venue LAN and node running. Wait for the node scheduler, or run `php artisan ingress:sync-admissions CLOUD_EVENT_ID` on the node.
2. Confirm the organizer attendance page shows the test entries, exits, guest/alumni movements, walk-in issuance, and recovered pass actor, reason, and identity attestation. Compare counts with the node event and gate notes.
3. Run the sync command again. The same requests must return the original receipts and must not duplicate admissions or walk-ins. Confirm the node has no pending batches after successful receipts.
4. Restart the same node application service while keeping its database intact. Confirm its grant and local admissions persist and synchronization resumes. Do not restore this database to a second live node.
5. The automated PostgreSQL harness covers the response-lost-after-cloud-commit case with separate cloud and node databases. Keep that automated CI result as evidence; do not simulate a lost response by changing a live database.

## 9. Exercise safe failure behavior

1. While WAN is down and the LAN is up, stop the node application service. Scanners must show the node unavailable and must not record or queue an admission locally. Restart the same node and database; confirm service resumes with the same authority.
2. While the node is healthy, interrupt only cloud sync. Admissions must continue at the node and pending batches must remain there. Restore cloud connectivity and confirm retry/reconciliation without duplicates.
3. Try a node URL with an untrusted certificate and a pairing code for the wrong node. The mobile app must reject both; do not enable local HTTP in any build to bypass the failure.
4. If the active node or its database is lost, stop the event at every gate. Do not promote a backup or cloned database while the original could still admit. There is no safe automated post-grant authority recovery; unresolved authority means admissions stay stopped.

## 10. Results and exit criteria

Mark each row only after it has been run on the named devices. Record date, tester, commit, APK build ID, Android version, gate, network state, printer model, result, and a redacted evidence location.

| Test | Result | Evidence / notes |
| --- | --- | --- |
| Docker Desktop WSL2 startup, migration, and service health | Pending | |
| Docker scanner listener on staff LAN; operator port loopback-only | Pending | |
| PostgreSQL volume persistence, encrypted backup, and isolated restore | Pending | |
| Docker Event Node and scheduler resume after Windows reboot | Pending | |
| APK installed; camera-only scanner permission | Pending | |
| Optional Resend event-manager invitation email | Pending if enabled; otherwise N/A | |
| Firebase student email verification and password recovery | Pending until tested with an authorized institutional mailbox | |
| Synthetic student website sign-in, registration, ticket PDF download | Pending | |
| Ticket transfer before finalization | Pending | |
| Accountless guest transfer from A to B to C; prior redemption codes rejected | Pending | |
| Snapshot receipts and single-node authority activation | Pending | |
| Staff pairing, session restore, and gate scoping | Pending | |
| WAN disconnected; downloaded PDF QR; preview then confirmed admission | Pending | |
| Multi-gate anti-passback, exit, and re-entry policies | Pending | |
| Guest, alumni, walk-in, capacity, and printed QR scan | Pending | |
| WAN recovery, retry synchronization, and node restart | Pending | |
| Safe shutdown after reconciliation; node and scheduler resume after reboot | Pending | |
| Node outage and fail-closed recovery procedure | Pending | |

Physical testing is complete only after every applicable row passes, stop conditions are resolved, the event reconciles without duplicates, and the owner accepts the documented manual recovery and retention limits. A pass here records field evidence; it does not by itself establish production readiness.

## 11. Safely stop and restart the Event Node

Keep the Event Node and venue LAN running until pending admissions, walk-ins, and recovery records have synchronized. The sync command can process bounded batches, so a zero exit code alone does not prove that the cloud has caught up.

1. Tell staff to stop new scans and finish or cancel any open preview. Keep the event's Event Node as the sole authority.
2. Restore WAN access. For Docker, from the WSL `api` directory run `docker compose -f compose.node.yaml --env-file node-compose.env run --rm ingress-cli ingress:sync-admissions CLOUD_EVENT_ID`; for native WSL or IIS, from `C:\Ingress\api` run `php artisan ingress:sync-admissions CLOUD_EVENT_ID`. Repeat after transient failures. Compare the cloud attendance and recovery history with the gate log and the synthetic walk-in and recovery records from the test. If the command exits nonzero, the records differ, or the cloud is unavailable, leave the node and database running and continue retrying when connectivity returns.
3. After the records match, use the commands for the selected profile. For Docker, confirm the scheduler is no longer sending pending work, then stop the node web service and scheduler. The database volume remains attached; do not remove it:

   ```bash
   docker compose -f compose.node.yaml --env-file node-compose.env ps
   docker compose -f compose.node.yaml --env-file node-compose.env stop ingress-node ingress-scheduler
   ```

   For native WSL, leave its cron service enabled and gracefully stop the Linux services in Ubuntu:

   ```bash
   sudo systemctl stop nginx php8.5-fpm postgresql
   ```

   For IIS, confirm the scheduled sync task is not running and its last result is `0`; keep the task enabled so its startup trigger resumes sync after the next boot. In elevated PowerShell, stop the IIS website and app pool when using IIS:

   ```powershell
   Get-ScheduledTask -TaskName 'Ingress Laravel Scheduler'
   Get-ScheduledTaskInfo -TaskName 'Ingress Laravel Scheduler'
   Stop-Website -Name 'IngressNode'
   Stop-WebAppPool -Name 'IngressNode'
   ```

4. Leave the PostgreSQL database, imported snapshot, authority grant, and logs intact. Shut down Windows through the Start menu after the selected web service has stopped; do not force power off or delete, reset, or clone the active node database.
5. To resume testing with Docker, start Docker Desktop, open the same WSL distro and checkout, then run:

   ```bash
   docker compose -f compose.node.yaml --env-file node-compose.env up -d
   docker compose -f compose.node.yaml --env-file node-compose.env ps
   ```

   For native WSL, start PostgreSQL, PHP-FPM, Nginx, and cron from Ubuntu:

   ```bash
   sudo systemctl start postgresql php8.5-fpm nginx cron
   ```

   For IIS, start PostgreSQL and IIS, then start the app pool and website:

   ```powershell
   Start-Service -Name 'postgresql-x64-18'
   Start-Service -Name 'W3SVC'
   Start-WebAppPool -Name 'IngressNode'
   Start-Website -Name 'IngressNode'
   Get-ScheduledTaskInfo -TaskName 'Ingress Laravel Scheduler'
   Invoke-WebRequest -Uri 'https://node.example.edu/up' -TimeoutSec 15
   ```

   Replace `node.example.edu` with the configured node hostname. Confirm the phone trusts its certificate before resuming scans. If the node cannot start or the cloud and node totals no longer match, keep admissions stopped and follow the stop conditions above.
