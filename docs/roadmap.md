# Implementation roadmap

Status is relative to `main` at `9242a4d`. A checked item requires a merged change and the verification stated in that item. Existing automated tests do not replace device and venue testing.

## Baseline

- [x] The MVP has a single LAN server, authenticated event registration, opaque tickets, read-only scan preview, and explicit admission confirmation. CI includes backend, mobile, TypeScript, and PostgreSQL concurrency jobs.
- [ ] Real-device MVP workflow verification is deferred to the project owner after implementation. PR #15 adds unverified self-registration and must not be treated as institutional verification.
- [x] Correct the institutional verification design ([PR #16](https://github.com/lucifron28/Ingress/pull/16)). Continue updating this roadmap as phases merge.
- [ ] Review and remediate applicable mobile dependency advisories. `npm audit --omit=dev` reported 19 advisories on 2026-11-03, including 6 high severity; applicability and fixes need review.

## Dependency order

1. [ ] Stabilize the MVP and verify the end-to-end LAN workflow, including failure and restart behavior.
2. [ ] Define and enforce separate cloud and Event Node runtimes, databases, and event authority. Runtime database binding and fail-closed route guards are implemented; snapshot handoff, safe migration of existing databases, and node admission remain pending. Preserve existing admission invariants.
3. [ ] Complete finalization, versioned snapshots, verified transfer, and split-brain prevention. Scheduled and organizer-initiated registration cutoff and provisional encrypted snapshot preparation are implemented. Authenticated manual transfer and idempotent node staging are implemented. Snapshot schema and roster validation are enforced before preparation and staging. A node can import or refresh a staged version with local ID mappings and return a distinct authenticated import receipt before admission; authority handoff and safe recovery remain pending. Test interrupted transfers and recovery.
4. [ ] Add secure offline student ticket caching and session restoration. Native apps cache the wallet after registration or an online wallet refresh and show the saved QR on transport failure. Device verification remains pending. Cached tickets provide display only, not admission or registration authority.
5. [ ] Add Event Node staff authentication, scanner pairing, and staff-only manual LAN configuration. Node-local staff credentials, restricted API tokens, and separate native mobile sign-in are implemented. QR pairing, authority handoff, and node scanning remain pending.
6. [ ] Add idempotent Event Node to cloud reconciliation, retries, and recovery.
7. [ ] Add institutional email verification and account completion. The cloud issues a single-use link to an `@student.mseuf.edu.ph` address and creates an account after form submission without a directory import. Mail delivery configuration and device verification remain to be checked in deployment.
8. [ ] Add guest and alumni tickets, walk-ins, capacity enforcement, and printable QR passes using the shared validation engine.
9. [ ] Add entry, exit, re-entry policies, and current-presence reporting.
10. [ ] Add pre-finalization transfers with recipient acceptance, credential rotation, and audit history.
11. [ ] Complete reporting, identity policies, security hardening, deployment instructions, and real-device verification.
12. [ ] Test 500 to 1,000 or more attendees, concurrent gates, LAN disconnection, restart, and synchronization recovery. Record setup, results, and limits.

Each phase starts from current `main` and ends only after its focused PR passes required checks, is safely merged, and the resulting `main` CI passes. Update this checklist with the PR, merge commit, and evidence as work completes. Adjust ordering only when implementation dependencies require it.
