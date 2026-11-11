# Implementation roadmap

This roadmap records completed work and the redesign plan against verified main at `fcab45a7f8fee72f8efb6e0bf285ce883f7a2671` (PR #72, scanner-only mobile cutover, merged 2026-11-11). A checked item means the implementation merged and its listed automated verification passed. Physical-device and venue verification is still pending with the project owner.

## Baseline

- [x] The initial MVP added authenticated event registration, opaque tickets, read-only scan preview, and explicit admission confirmation. The runtime now separates cloud ticketing from Event Node admission. CI covers backend, mobile, TypeScript, and PostgreSQL concurrency jobs.
- [ ] Real-device MVP workflow verification remains with the project owner. PR #15 adds self-reported signup details; email control does not establish student enrollment.
- [x] Correct the institutional verification design ([PR #16](https://github.com/lucifron28/Ingress/pull/16)).
- [x] Review and remediate applicable dependency advisories. The `symfony/mime` advisory `PKSA-wtxr-p26d-nn42` was fixed by upgrading to a patched release. The mobile app moved to Expo SDK 58 and its transitive `glob` and `minimatch` dependencies were patched. On 2026-11-05, Composer reported six new Symfony advisories; `symfony/http-foundation`, `symfony/http-kernel`, and `symfony/routing` were upgraded to `8.1.11`. Composer and npm audits now report no vulnerabilities, and CI runs both audits.

## Stabilization audit

- [x] Patch newly reported Symfony advisories, run Composer and npm audits in CI, and align the concurrency-test PostgreSQL service with the documented PostgreSQL 18 deployment. PR #40 merged as `c9c79fa` on 2026-11-05; all three required checks and all three post-merge checks passed. The full local suite passed 222 backend tests (221 passed, 1 skipped), PostgreSQL concurrency verification, 1,000-attendee load/recovery, 60 mobile checks, and TypeScript.
- [x] Enable main-branch protection requiring pull requests, an up-to-date branch, and all three required CI jobs, including for administrators; force pushes and branch deletion are disabled.
- [x] Document fail-closed handling for Event Node outages and the current recovery limits. Safe automated authority recovery is still unavailable while an offline node may retain an active grant. Retention automation and safe migration of existing databases also remain open operational work.
- [x] Refresh GitHub Actions to Node 24 and pin hosted runners to Ubuntu 24.04, replacing the deprecated Node 20 defaults and moving-runner alias. PR #41 merged as `b01959c` on 2026-11-05; all three required PR checks and all three post-merge CI jobs passed.
- [x] Refresh the CI runtime used by the PostgreSQL job. PR #42 merged as `3d7ee6d` on 2026-11-05; all three required PR checks and all three post-merge CI jobs passed.
- [x] Harden Android release configuration. PR #43 merged as `07385a9` on 2026-11-05; release profiles now require a real HTTPS cloud API URL, disable demo and HTTP flags, and remove unused microphone, broad storage, and overlay permissions. Six build-configuration tests, 60 mobile session checks, TypeScript, npm audit, Expo Doctor (20/20), and release-mode Android prebuild passed locally. The generated release manifest retained camera and internet permissions and removed microphone, broad storage, and overlay permissions. All three required PR checks and all three post-merge CI jobs passed.
- [x] Add cloud staging account/venue provisioning, a static Resend connectivity check, and guarded Event Node registration for Render Free. PR #47 merged as `8afd495` on 2026-11-09. Local tests passed: 243 backend tests (1 skipped), PostgreSQL concurrency, 1,000-attendee load/recovery, and migration-lock contention. All four PR jobs and all four post-merge main CI jobs passed. Live provider, Render, and device tests remain unrun.
- [x] Prepare Windows IIS/FastCGI Event Node setup and complete the current Render Android staging instructions. PR #48 merged as `f0cb3a3` on 2026-11-09. Local checks passed: 245 backend tests (1 skipped), PostgreSQL concurrency and 1,000-attendee recovery, 60 mobile session checks, 7 EAS configuration tests, TypeScript, audits, and the Docker startup test with missing Nginx temporary directories. PR checks and post-merge main CI run `42621183840` passed all four jobs. Physical-device, venue, and live provider tests remain unrun.

## Cloud, guest, and scanner redesign

This phase extends the earlier Event Node roadmap. The source audit for PDF ticket delivery starts from main at `6a7dcb8331a865e91a453e9cb7b22d5c3ff72c4e`, fetched on 2026-11-11. Keep Cloud account authentication, Event Node operator authentication, and Event Node scanner credentials separate.

### Implementation at the phase 4 branch base

Phase 4 starts from `main` at `6a7dcb8331a865e91a453e9cb7b22d5c3ff72c4e`, after PR #67 merged.

| Area | Behavior at the phase 3 branch base |
| --- | --- |
| Cloud identity | Platform administrators and legacy organizers use password login. Students can use Firebase web sign-in; Laravel verifies the ID token, verified email, password provider, and exact student domain. The ingress:create-admin Artisan command provisions a Cloud admin against the Cloud PostgreSQL runtime. |
| Event permissions | events.organizer_id identifies an event owner. Active event-manager assignments grant event-scoped management. event_staff remains separate for scanner assignments with an optional gate. |
| Registration | Public catalog and event pages expose ticket categories and eligibility. Free public categories allow accountless guest checkout; student-restricted categories require a verified institutional account. Registration paths use event locking, duplicate checks, and capacity enforcement. |
| Tickets and transfers | Tickets keep the opaque ingress:v1:<ticket_public_id>:<random_secret> admission credential. Existing transfers require user accounts and recipient acceptance; guest checkout has no rotating redemption code yet. |
| Payments and PDFs | Paid checkout is not implemented. Tickets can be shown as a protected HTML pass, but on-demand PDF download is not implemented at this branch base. |
| Mobile | Expo Router has separate (attendee) and (staff) route groups. The attendee group contains the wallet, event pages, profile, and account-based transfers. The staff group contains the Event Node scanner and walk-in workflows. |
| Event Node | The node has its own PostgreSQL runtime identity, operator accounts, scanner pairing, local admission, signed authority handoff, and retry-safe synchronization. Preserve these paths as the local admission contract. |

A record in node_operators belongs to the Event Node console. It does not grant Cloud event-administration access. The ingress:create-admin command prompts for the Cloud administrator’s name, email, and password; it does not expose an existing password or establish whether an account exists in a deployed database.

### Sequential implementation plan

Each code phase starts from the latest verified main, adds focused automated coverage, opens a pull request, and waits for required checks and approvals before merging. The next phase starts only after post-merge main CI passes.

| Phase | Scope and dependency |
| --- | --- |
| 0. Audit and architecture foundation | Record the current baseline, shared invariants, and delivery order. This roadmap update is phase 0. |
| 1. Institutional identity | [x] PR #65 merged as `106c00157de51e8f44497b3145a5e985a66ae02f`. Firebase web sign-in verifies ID tokens and email ownership for `@student.mseuf.edu.ph`; the Firebase UID binds to one local student account. PR and post-merge CI passed all six jobs. The local Laravel suite passed 315 tests (314 passed, 1 skipped, 2,420 assertions). Email control does not prove current enrollment. |
| 2. Event-scoped authorization | [x] PR #66 merged as `6376c887cf672a60ef4afc6a64061b748ebbea58`. Event ownership remains intact; event managers are assigned and audited per event; invitations are single-use and expire; scanner event/gate authority remains separate. All six PR checks and all six post-merge main jobs passed. The local backend suite passed 330 tests (329 passed, 1 skipped, 2,564 assertions). |
| 3. Public discovery and free registration | [x] PR #67 merged as `6a7dcb8331a865e91a453e9cb7b22d5c3ff72c4e`. All six PR checks (`42861199765`) and all six post-merge main checks (`42861625678`) passed. The local backend suite passed 342 tests (341 passed, 1 skipped, 2,676 assertions). |
| 4. PDF ticket delivery | [x] PR #68 merged as `3dd558070fc41ae7d17186f4d709c6a3806f3efe`. All six PR checks and all six post-merge main CI jobs (`42867514610`) passed. The local backend suite passed 351 tests (350 passed, 1 skipped, 2,751 assertions); the PDF delivery suite passed 9 tests. Tickets are generated on demand, account downloads enforce ownership, and guest access uses a short-lived session handoff. |
| 5. Guest ticket transfer | [x] PR #69 merged as `84d9cc77aed56d843e5b503f5cf3d8631d664a26`. Guest and alumni tickets use separate 256-bit redemption codes stored as hashes; successful redemption atomically rotates both the redemption code and admission QR. The follow-up identity hardening updates the recipient's name and contact email in the same transaction. A-to-B-to-C tests cover current pass and PDF identity, scanner identity-check preview, and snapshot identity. Rate limits, event finalization, admission, revoked tickets, and student-restricted eligibility remain enforced. |
| 6. Simulated paid checkout | [x] PR #70 merged as `50c515ef94a7efd671009259893b7d6ea20738ee`. All six PR checks (`42932443938`) and all six post-merge main jobs (`42932730690`) passed. The local backend suite passed 378 tests (377 passed, 1 skipped, 3,080 assertions); the targeted checkout suite passed 18 tests (162 assertions), Pint and Composer audit passed, and PostgreSQL 18 final-seat concurrency verification passed. No payment provider or financial credentials were added. |
| 7. Event management and reporting | [x] PR #71 merged as `92942825f77fc223fa3439a81d2268bc01ce4091`. Added admin-only venue creation and editing, event-scoped gate editing, inactive-gate assignment checks, and a server-side permission check that keeps unrelated organizers from viewing management details. Venue defaults remain hints; event capacity controls registration. Venue edits lock linked events and stop after snapshot preparation or authority transfer. Gate edits stop after snapshot preparation or admissions. Local verification: 391 backend tests (390 passed, 1 skipped, 3,130 assertions); focused event-management tests passed (47 tests, 322 assertions); Composer audit, Pint, and diff checks passed. PostgreSQL 18 concurrency verification passed 160 assertions, including a race between gate deactivation and scanner assignment. All six PR jobs and six post-merge main CI jobs (run `42937838617`) passed. |
| 8. Scanner-only mobile cutover | [x] PR #72 merged as `fcab45a7f8fee72f8efb6e0bf285ce883f7a2671`. Six PR CI jobs (`42945770496`) and six post-merge `main` jobs (`42946034084`) passed. Expo Router now exposes only staff login, assigned events, scanning, and walk-ins under `mobile/src/app`; attendee screens and Cloud account/wallet services are removed. Event Node pairing, HTTPS-only requests, secure storage, identity checks, scan preview and confirmation, retry identity, and walk-ins remain. Android system CA trust is used by preview and production. The separate `lanDemo` APK adds one public CA for one exact Event Node host. Local verification: 13 Node session tests, 16 route/build-configuration tests, lint, TypeScript, npm audit (0 vulnerabilities), Expo Doctor (20/20), native config generation, and EAS preview APK build [`e34a766e-278d-4e18-bf13-6ec7c6623679`](https://expo.dev/accounts/lucifron28/projects/ingress/builds/e34a766e-278d-4e18-bf13-6ec7c6623679). Installation and physical-device verification remain pending. |
| 9. Integration and hardening | [x] PR #73 merged as `c7145afb6d2963b816bebce8a5732eca2f212d91`. All six PR CI jobs (`42949318596`) and all six post-merge `main` jobs (`42949630647`) passed. Added production-mode browser CSRF tests, an offline scanner request test, and Event Node Docker coverage for non-destructive operator provisioning and operator identity/hash persistence across an isolated database container restart. `docs/acceptance-traceability.md` maps the 26 required flows and security boundaries to test evidence and records live/device limitations. Local checks passed: backend 393 tests (392 passed, 1 skipped, 3,135 assertions), all 30 mobile tests, lint, TypeScript, npm audit (0 vulnerabilities), Composer audit, Pint, shell syntax, PostgreSQL 18 concurrency verification, and 1,000-attendee PostgreSQL load/recovery (9 assertions). Docker integration passed in CI; local Windows Bash could not reach Compose. Physical-device, live Firebase/email, deployed Render, printer, and venue WAN verification remain pending with the project owner. |

Phase 1 adds Firebase-backed student web signup, verification, password recovery, and Cloud session exchange. Phase 2 adds event-specific manager assignment. Public registration adds ticket categories before PDF and payment work because eligibility and category capacity must be enforceable at checkout; paid categories remain unavailable until phase 6. Phase 8 removes the legacy attendee login and Cloud mobile client after the equivalent web flows are available. Firebase credentials and live email delivery must be configured and verified in the Firebase project after repository work; email verification alone does not prove enrollment.

### Post-Phase-9 security audit

| Finding | State | Scope |
|---|---|---|
| Paid guest checkout access after transfer | Complete — PR #75 | Checkout session authorization follows the current ticket owner; a previous successful checkout cannot retrieve credentials after a transfer. |
| Mandatory Cloud Resend dependency | Complete — PR #76 | Cloud startup no longer requires Resend; secure mail configuration and Firebase student verification remain separate. |
| Guest identity after transfer | Complete — PR #77 | Guest redemption updates current attendee details and rotates credentials atomically. |
| Unverified guest email blocking student identities | Complete — PR #78 | Cloud registrations, active guest checkout reservations, student ticket transfers, and schema-v4 snapshots distinguish account IDs from unverified guest contact emails. Guest-guest duplicate protection, student eligibility, and event capacity remain enforced. |
| Predictable demo payment outcomes | Complete — PR #79 | Each attempt persists an explicit demo success or failure; confirmation reads only that saved selection, rejects invalid values, preserves both simulator authorization switches, and issues one ticket under concurrent retries. |

### Protected invariants

- Event Node admission remains authoritative on the venue LAN and does not depend on Render, Neon, or Firebase.
- Snapshot payloads and QR validation remain compatible with existing Event Node import, signed grants, admission, and synchronization.
- Ticket category and eligibility data are Cloud registration metadata. They are not serialized into the strict snapshot schema; imported Event Node registrations may leave `ticket_category_id` null, and node admission continues to validate the existing opaque ticket credential.
- Event managers receive no global authority from a user role alone. Scanner access remains limited to event and gate assignments.
- Public guest checkout collects only needed contact details. Redemption secrets never enter admission QRs, PDFs, public URLs, logs, analytics, or referrers.
- Guest redemption updates the current attendee name and contact email but does not verify identity. A valid code proves ticket control only; identity-check events still require staff to compare the attendee with approved photo ID.
- Simulated payment authorization is explicit and cannot issue a paid ticket from a client-controlled status value.
- Render’s ephemeral filesystem stores no durable application state. Existing HTTPS, database TLS verification, production mail, proxy, and runtime-identity checks stay fail-closed.

### Audit baseline and verification

The read-only audit used Graphify’s deterministic code extractor for api and mobile; the repository has no .codegraph index. No source code was changed on main.

- php artisan test --compact: 302 tests, 301 passed, 1 skipped, 2,306 assertions.
- PostgreSQL 18 concurrency verification: all reported cases passed, including capacity, admission, identity binding, ticket transfer, snapshot, pairing, and synchronization races.
- PostgreSQL load/recovery: 9 assertions passed with 1,000 attendees and 4 scanner workers; cloud and node converged to 1,000 admissions with no pending batches.
- PostgreSQL Render migration-lock contention: passed.
- Mobile session tests: 73 checks passed. Mobile build-configuration tests: 7 passed. npx tsc --noEmit: passed.
- composer audit --locked: no advisories. npm audit: 0 vulnerabilities.
- The local Event Node Docker integration script was not verified on this Windows host: WSL’s Docker client had no socket, and Git Bash failed to resolve its temporary Compose env file. The script did not reach service startup. Linux GitHub Actions remains the verification path for this container test.

## Dependency order

1. [x] Implement the MVP with authenticated registration, opaque tickets, read-only scan preview, and explicit admission confirmation. The implementation merged in PR #11 and later phases extended it. Real-device MVP verification is still pending.
2. [x] Separate the cloud and Event Node runtimes, databases, and event authority. Runtime database binding and fail-closed route guards are implemented. Safe migration of existing databases and recovery after authority grant remain pending. Preserve the admission invariants during any later changes.
3. [x] Implement event finalization, versioned snapshots, verified transfer receipts, signed grants, and single-node authority. Registration cutoff, encrypted snapshot preparation, authenticated manual transfer, idempotent staging/import, and cloud/node locks are implemented. Automated separate-database tests cover transfer and admission. Safe recovery after grant remains pending.
4. [x] Implement offline student ticket display and session restoration. The native app caches the wallet after registration or an online refresh and displays the saved QR after transport failure. Cached tickets do not grant registration or admission authority. Device verification remains pending.
5. [x] Implement Event Node staff authentication, scanner pairing, and manual LAN configuration. Node-local credentials, restricted API tokens, separate mobile sign-in, and short-lived one-use pairing are implemented. Authority recovery remains pending.
6. [x] Implement idempotent Event Node to cloud reconciliation, scheduled retries, and walk-in recovery. Separate-PostgreSQL tests cover snapshot transfer, node admission, replay after a lost response, and cloud reconciliation. The load and recovery run admitted 1,000 attendees across four gates. Safe authority recovery remains pending.
7. [x] Implement Firebase student authentication. Firebase handles Email/Password sign-in, verification, and password-reset messages. Laravel verifies Firebase ID tokens and accepts only verified `@student.mseuf.edu.ph` identities. Resend remains optional for event-manager invitations.
8. [x] Add guest and alumni tickets, walk-ins, capacity enforcement, and printable QR passes using the shared validation engine. Organizers can issue and print accountless guest and alumni passes before registration closes. PR #34 merged as `47feca7`; CI passed. Device verification remains deferred.
9. [x] Add entry, exit, re-entry policies, and current-presence reporting. PR #35 merged as `9226683`; Laravel tests passed (203 passed, 1 skipped), TypeScript passed, and PostgreSQL concurrency checks passed (134 assertions). All three post-merge CI jobs passed. Device verification remains deferred.
10. [x] Add pre-finalization transfers with recipient acceptance, credential rotation, and audit history. PR #36 merged as `f854502`; backend, mobile, TypeScript, PostgreSQL concurrency, and post-merge CI checks passed. Device verification remains deferred.
11. [x] Complete reporting, identity policies, security hardening, and deployment instructions. PR #37 merged as `d788667`; backend tests passed (221 passed, 1 skipped, 1,641 assertions), PostgreSQL concurrency passed, mobile session checks passed (60), TypeScript passed, Expo Doctor passed (20/20), dependency alignment and composer/npm audits passed, and Android Kotlin compilation passed. All three PR and post-merge CI jobs passed. Device and venue verification remains deferred.
12. [x] Test 500 to 1,000 or more attendees, concurrent gates, LAN disconnection, restart, and synchronization recovery. PR #38 merged as `9789bb9`; the PostgreSQL run admitted 1,000 attendees across four gates and recovered from a lost response after the cloud commit with no duplicates. All nine local assertions and all three PR and post-merge CI jobs passed. Setup, results, and limits are in [`load-recovery.md`](load-recovery.md). Device and venue verification remains deferred.

Each phase starts from current `main` and ends only after its focused PR passes required checks, is safely merged, and the resulting `main` CI passes. Update this checklist with the PR, merge commit, and evidence as work completes. Adjust ordering only when implementation dependencies require it.

## Physical-test readiness

- [x] Write a staging procedure for the internal Android APK, Cloud website, Event Node LAN, staff pairing, saved ticket PDF display, admission, printed passes, synchronization, node restart, and fail-closed recovery. See [physical-device-test-plan.md](physical-device-test-plan.md).
- [x] Re-run required CI after PR #43 merged. Main run `41950459357` passed backend tests, mobile session and build-configuration tests plus TypeScript, and PostgreSQL concurrency and load/recovery checks.
- [x] Review Android release permissions with Expo prebuild. Local release-mode prebuild succeeded and its manifest contains camera and internet access while removing microphone, broad storage, and overlay permissions.
- [ ] Install EAS preview APK build [`e34a766e-278d-4e18-bf13-6ec7c6623679`](https://expo.dev/accounts/lucifron28/projects/ingress/builds/e34a766e-278d-4e18-bf13-6ec7c6623679), then run the field plan. The project has no local Android SDK; Expo prebuild checked native policy generation, and EAS produced the APK. Device, venue-network, printer, and end-to-end results remain with the project owner.

The audit meets the software-side entry criteria for physical testing: required automated checks pass and the remaining deployment steps and safety limits are recorded. It does not establish production readiness. Keep gates closed if the active node's authority or database state is uncertain.

## Remaining operational limits

- A node with a signed authority grant cannot be fenced or replaced safely by the current recovery flow. If its status is uncertain, stop admission at every gate and do not activate a clone.
- Snapshot delivery is manual. Automatic snapshot and roster retention/deletion is absent; approve a retention schedule and operator deletion procedure before real attendee data is used.
- Existing databases cannot be safely migrated between the legacy single-runtime setup and the cloud/node split. Use fresh databases for a deployment.
- Organizers must deliver guest and alumni passes through a private channel. Automated pass email delivery and real payment-provider integration are not implemented; paid checkout remains simulated.
- Firebase email delivery and authorized-domain settings still require live verification with an institutional account. Resend is optional for event-manager invitations. Cloud and node TLS, trusted-device certificates, and the venue network must be configured by the operator. The isolated LAN-demo EAS profile also needs the public CA file and exact Node host described in [`mobile/README.md`](../mobile/README.md).
- No local Android SDK is installed, so a local Gradle build was not run. EAS built the preview APK, but device installation, venue-network, printer, and end-to-end checks remain pending.
