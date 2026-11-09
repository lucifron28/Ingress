# Implementation roadmap

This audit starts from `main` at `8afd495` (staging operator workflows merged, 2026-11-09). A checked item means the implementation merged and its listed automated verification passed. Physical-device and venue verification is still pending with the project owner.

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
