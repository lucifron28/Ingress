# Implementation roadmap

This roadmap records completed work and the redesign plan against main at be3e01b0bf83a420e6d8c9e8e161192d3d96cb95 (Event Node deployment documentation merged, 2026-11-10). A checked item means the implementation merged and its listed automated verification passed. Physical-device and venue verification is still pending with the project owner.

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

This phase extends the earlier Event Node roadmap. The source audit is based on main at be3e01b0bf83a420e6d8c9e8e161192d3d96cb95, fetched on 2026-11-10. Keep Cloud account authentication, Event Node operator authentication, and Event Node scanner credentials separate.

### Current implementation

| Area | Current behavior on main |
| --- | --- |
| Cloud identity | Password login supports platform admins and organizers. Students complete an emailed activation link; the code does not use Firebase. The ingress:create-admin Artisan command provisions a Cloud admin against the Cloud PostgreSQL runtime. |
| Event permissions | events.organizer_id identifies an event owner. EventPolicy grants event management to that owner or a platform admin. event_staff stores scanner assignments with an optional gate. |
| Registration | RegisterForEvent creates authenticated registrations under an event-row lock. IssueGuestTicket creates organizer-issued guest or alumni registrations; there is no public guest checkout page. |
| Tickets and transfers | Tickets keep the opaque ingress:v1:<ticket_public_id>:<random_secret> admission credential. Existing transfers require user accounts and recipient acceptance. |
| Payments and PDFs | The app has no payment-order or ticket-category schema and no paid checkout. Organizers can print a protected HTML guest-pass page; there is no PDF download flow. |
| Mobile | Expo Router has separate (attendee) and (staff) route groups. The attendee group contains the wallet, event pages, profile, and account-based transfers. The staff group contains the Event Node scanner and walk-in workflows. |
| Event Node | The node has its own PostgreSQL runtime identity, operator accounts, scanner pairing, local admission, signed authority handoff, and retry-safe synchronization. Preserve these paths as the local admission contract. |

A record in node_operators belongs to the Event Node console. It does not grant Cloud event-administration access. The ingress:create-admin command prompts for the Cloud administrator’s name, email, and password; it does not expose an existing password or establish whether an account exists in a deployed database.

### Sequential implementation plan

Each code phase starts from the latest verified main, adds focused automated coverage, opens a pull request, and waits for required checks and approvals before merging. The next phase starts only after post-merge main CI passes.

| Phase | Scope and dependency |
| --- | --- |
| 0. Audit and architecture foundation | Record the current baseline, shared invariants, and delivery order. This roadmap update is phase 0. |
| 1. Institutional identity | Add Firebase web email/password signup, verification, and recovery for @student.mseuf.edu.ph addresses. Laravel must validate Firebase ID tokens and email_verified, bind the Firebase UID to a stable local user, and enforce student-only eligibility server-side. Email control does not prove current enrollment. Support provisioned or invited faculty/employee accounts without directory integration. Keep existing Cloud admin authentication and Event Node local accounts separate. |
| 2. Event-scoped authorization | Restrict platform administration to platform admins. Assign event managers to specific events, audit assignment and revocation, and retain event_staff for scanner event/gate authorization. Preserve existing organizer_id data while the authorization migration is introduced. Event managers receive no global authority from a role. |
| 3. Public discovery and free registration | Add a public Blade event catalog with event details, schedules, ticket categories, publication status, and eligibility rules. Allow accountless guest registration for public tickets; require a verified student account only for student-restricted benefits. Use shared transactional capacity and duplicate-registration rules. |
| 4. Simulated paid checkout | Add ticket categories, demo-only payment state, idempotent confirmation, and expiring capacity reservations. Model pending, successful, failed, cancelled, and expired attempts; release expired reservations safely. Require explicit demo authorization, never a client-controlled payment status or APP_ENV toggle. Run seat-race tests against PostgreSQL. No real payment provider or payment credentials are part of this phase. |
| 5. PDF ticket delivery | Generate printable PDFs from current database state on demand. Protect account tickets against IDOR and deliver accountless checkout tickets through a short-lived, non-cacheable handoff. Keep the QR in the existing opaque admission format and exclude transfer secrets. |
| 6. Guest ticket transfer | Add a separate, one-time redemption token with at least 128 bits of entropy, hash it at rest, and rotate it with the admission QR in one locked transaction. This follows ticket categories and checkout so free and paid public tickets use the same ownership rules. Rate-limit attempts and block transfer after eligibility failure, admission, cancellation, or snapshot/handoff. Student-restricted tickets keep verified-recipient requirements. |
| 7. Event management and reporting | Extend the existing organizer portal into platform-admin and assigned event-manager workflows for event settings, venues, schedules, categories, staff/gates, registration, snapshots and receipts, authority handoff, attendance reports, CSV, and audit history. |
| 8. Scanner-only mobile cutover | Remove attendee screens and wallet/transfer services only after the equivalent web ticket, account, and transfer flows are implemented. Keep Event Node login, pairing, gate selection, preview/confirmation, walk-ins, and secure storage. Build a standalone Android release with bundled JavaScript and keep any local-CA demo configuration isolated from production. Admission must use only the reachable authoritative Event Node on the LAN. |
| 9. Integration and hardening | Run the 26 acceptance workflows across Cloud, PostgreSQL, scanner APIs, snapshots, and synchronization. Review authorization, IDOR, CSRF, replay, concurrency, logging, and production configuration. Report device, live Firebase/email, Render, and WAN-outage checks only when observed. |

### Protected invariants

- Event Node admission remains authoritative on the venue LAN and does not depend on Render, Neon, or Firebase.
- Snapshot payloads and QR validation remain compatible with existing Event Node import, signed grants, admission, and synchronization.
- Event managers receive no global authority from a user role alone. Scanner access remains limited to event and gate assignments.
- Public guest checkout collects only needed contact details. Redemption secrets never enter admission QRs, PDFs, public URLs, logs, analytics, or referrers.
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
7. [x] Implement institutional email verification and account completion. The cloud sends a single-use link to an `@student.mseuf.edu.ph` address and creates an account after the recipient submits the completion form. The Render mail transport uses Resend's HTTPS API; verified-domain and institutional mailbox setup remains an operator task. Email control does not prove enrollment.
8. [x] Add guest and alumni tickets, walk-ins, capacity enforcement, and printable QR passes using the shared validation engine. Organizers can issue and print accountless guest and alumni passes before registration closes. PR #34 merged as `47feca7`; CI passed. Device verification remains deferred.
9. [x] Add entry, exit, re-entry policies, and current-presence reporting. PR #35 merged as `9226683`; Laravel tests passed (203 passed, 1 skipped), TypeScript passed, and PostgreSQL concurrency checks passed (134 assertions). All three post-merge CI jobs passed. Device verification remains deferred.
10. [x] Add pre-finalization transfers with recipient acceptance, credential rotation, and audit history. PR #36 merged as `f854502`; backend, mobile, TypeScript, PostgreSQL concurrency, and post-merge CI checks passed. Device verification remains deferred.
11. [x] Complete reporting, identity policies, security hardening, and deployment instructions. PR #37 merged as `d788667`; backend tests passed (221 passed, 1 skipped, 1,641 assertions), PostgreSQL concurrency passed, mobile session checks passed (60), TypeScript passed, Expo Doctor passed (20/20), dependency alignment and composer/npm audits passed, and Android Kotlin compilation passed. All three PR and post-merge CI jobs passed. Device and venue verification remains deferred.
12. [x] Test 500 to 1,000 or more attendees, concurrent gates, LAN disconnection, restart, and synchronization recovery. PR #38 merged as `9789bb9`; the PostgreSQL run admitted 1,000 attendees across four gates and recovered from a lost response after the cloud commit with no duplicates. All nine local assertions and all three PR and post-merge CI jobs passed. Setup, results, and limits are in [`load-recovery.md`](load-recovery.md). Device and venue verification remains deferred.

Each phase starts from current `main` and ends only after its focused PR passes required checks, is safely merged, and the resulting `main` CI passes. Update this checklist with the PR, merge commit, and evidence as work completes. Adjust ordering only when implementation dependencies require it.

## Physical-test readiness

- [x] Write a staging procedure for the internal Android APK, cloud setup, Event Node LAN, staff pairing, offline wallet, admission, printed passes, synchronization, node restart, and fail-closed recovery. See [physical-device-test-plan.md](physical-device-test-plan.md).
- [x] Re-run required CI after PR #43 merged. Main run `41950459357` passed backend tests, mobile session and build-configuration tests plus TypeScript, and PostgreSQL concurrency and load/recovery checks.
- [x] Review Android release permissions with Expo prebuild. Local release-mode prebuild succeeded and its manifest contains camera and internet access while removing microphone, broad storage, and overlay permissions.
- [ ] Set the EAS `preview` variable to `https://ingress-pp2u.onrender.com/api/v1`, build and install the preview APK, then run the field plan. The project has no local Android SDK, so the APK build and device installation remain operator actions. Device, venue-network, printer, and end-to-end results are intentionally unclaimed.

The audit meets the software-side entry criteria for physical testing: required automated checks pass and the remaining deployment steps and safety limits are recorded. It does not establish production readiness. Keep gates closed if the active node's authority or database state is uncertain.

## Remaining operational limits

- A node with a signed authority grant cannot be fenced or replaced safely by the current recovery flow. If its status is uncertain, stop admission at every gate and do not activate a clone.
- Snapshot delivery is manual. Automatic snapshot and roster retention/deletion is absent; approve a retention schedule and operator deletion procedure before real attendee data is used.
- Existing databases cannot be safely migrated between the legacy single-runtime setup and the cloud/node split. Use fresh databases for a deployment.
- Organizers must deliver guest and alumni passes through a private channel. Automated pass email delivery and online payments are not implemented.
- Resend sender-domain verification and institutional mailbox access remain needed for real student activation. Cloud and node TLS, trusted-device certificates, the venue network, and the EAS preview variable must be configured by the operator. The EAS URL is public client configuration, not a secret.
- The repository does not define ESLint configuration, so `npx expo lint` cannot run; CI's mobile session checks and TypeScript checks pass. No local Android Gradle build was possible without an Android SDK. Neither limit was bypassed or reported as a passing check.
