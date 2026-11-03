# Ingress second-half target architecture

**Status:** Planned for the remaining 50% of the project.  
**Current implementation:** The 50% milestone uses one local Laravel/PostgreSQL server on the venue LAN for student and staff mobile traffic, with two-stage ticket preview and explicit staff confirmation before admission is recorded. Cloud/Event Node synchronization, guest tickets, walk-ins, and ticket transfers are planned second-half functionality. Ingress removes the public internet from the critical event-day admission path, but still requires the venue LAN and Event Node. This document records the agreed target architecture so later implementation does not drift.

## Runtime boundary implemented so far

`INGRESS_RUNTIME=legacy` retains the single-server demo only when `APP_ENV` is `local` or `testing`. Cloud and node runtimes require PostgreSQL outside automated tests. Set a unique, stable `INGRESS_NODE_ID` on each node. Each database binds to the first runtime and node identity that uses it; a different identity receives HTTP 503. Configure separate PostgreSQL databases for cloud and each node. This binding prevents an accidental shared database, but it does not authenticate a node or prevent copies of a database from running independently.

Cloud permits student registration and organizer event changes, but rejects scanner endpoints. Node rejects registration, organizer operations, and scanner endpoints. Admission remains available only in the non-production legacy demo until verified snapshots and an authority handoff are implemented. Snapshot files can be staged and imported manually; admissions are not synchronized. Do not use split runtimes for event admission yet.

On the cloud, an organizer can prepare a versioned snapshot after registration closes. Preparation locks the event while it captures event details, gates, staff assignments, registrations, and ticket verification hashes. The snapshot is encrypted with the cloud application key at rest. It contains neither QR secrets nor account password hashes. Invalidated snapshots still contain encrypted identity data; automatic retention and deletion are not implemented. Editing the event, gates, or staff assignments requires discarding the prepared snapshot; cancellation invalidates it. Concurrent preparation returns the same snapshot version. A later preparation after discard creates a new version.

A prepared snapshot can be transferred as an encrypted file to one registered node. The cloud encrypts and authenticates the package with that node's transfer key. The node verifies the package, stages the payload encrypted with its own application key, and produces an authenticated receipt. The cloud verifies that receipt against the current snapshot and target node. Repeating receipt is idempotent. A node rejects an older version after receiving a newer one and rejects conflicting snapshots for the same event and version.

This is a manual staging workflow. A receipt proves possession of the node transfer key and records the receiver's claim that it staged the package; it is not hardware attestation. Preparation and staging validate the snapshot schema, roster references, ticket credentials, and capacity. Events with existing admissions cannot be prepared because admission history is not yet included. An operator can import the current staged version into node event, roster, gate, and ticket records. The import maps cloud IDs to node-local IDs, uses inaccessible random passwords for imported accounts, and is transactional and idempotent. It does not provision staff credentials, finalize admission, or transfer authority. A newer staged snapshot can replace the node import while admission is disabled. The refresh locks the event, rejects any existing admissions or records outside the previous import, and rolls back on conflicts. It preserves the node event ID but may change node-local gate, staff, registration, and ticket IDs. Identity or venue conflicts stop the import without changing the event. Removed users and prior encrypted snapshots remain on the node until a retention process is implemented. Imported records contain personal data in the node database and require the same access controls and storage protection as the cloud database. The node cannot admit attendees yet. There is no automatic delivery, retention policy, or safe recovery from a rolled-back node database. A future handoff must fence old snapshots and cloned nodes, restrict discard and cancellation after authority may leave the cloud, and verify operational import and admission invariants before enabling admission. Future user identity, staff credential, or ticket revocation changes must participate in the same lock and snapshot invalidation rules.

### Stage a snapshot on a node

1. On the cloud host, set `INGRESS_RUNTIME=cloud`, configure PostgreSQL and a cloud `APP_KEY`, and apply migrations. Run `php artisan ingress:register-node NODE_ID`. The command prints a random transfer secret once. Treat it as a password, keep it out of logs and shell history, and transfer it to the node through a secure operator channel.
2. On the node, configure a separate PostgreSQL database and independent `APP_KEY`, set `INGRESS_RUNTIME=node`, `INGRESS_NODE_ID` to the registered ID, and `INGRESS_NODE_SYNC_KEY` to that secret. Apply migrations and refresh cached configuration after changing it.
3. In the cloud organizer event page, close registration and prepare a snapshot. Enter the node ID and download the encrypted package. Outside local and testing environments the export and receipt endpoints require HTTPS. If TLS terminates at a reverse proxy, configure Laravel to trust only that proxy so it can recognize the secure request.
4. Copy the package to the node and run `php artisan ingress:receive-snapshot PATH`. The command stages it and prints a receipt. Return that receipt to the cloud organizer page. Re-importing the same current version after a restart returns the same receipt.
5. Run `php artisan ingress:import-snapshot CLOUD_EVENT_ID` on the node. It imports the current staged version and prints the node event ID. Admission remains disabled. Repeating the command for the same snapshot is safe. To refresh, discard and prepare a new snapshot on the cloud, transfer and stage it, then run the import command again. Refresh is refused if admissions or untracked event records exist. It does not fence copied nodes or transfer authority.
6. To disable a node's transfer credential on the cloud, run `php artisan ingress:revoke-node NODE_ID`. Revocation removes the cloud copy of the key and the ID cannot be registered again. It does not remotely erase a package already staged on an offline node. To use a replacement node, discard the provisional snapshot, prepare a new version, and register a new ID.

A package is bound to the first target node selected for its snapshot. The limit is 16 MiB per encrypted package. Invalidated snapshots and staged payloads retain encrypted identity data until an operator removes them under a future retention procedure.

### Provision node staff credentials

After importing a current snapshot, an operator with local node access can run `php artisan ingress:list-node-staff` to see assigned staff and their cloud user IDs. Run `php artisan ingress:provision-node-staff CLOUD_USER_ID` to issue a random local password. It is printed once; deliver it through a secure channel. Repeating the command rotates the password and revokes that user's node sessions. `php artisan ingress:revoke-node-staff CLOUD_USER_ID` revokes the local password and sessions, including after the assignment is removed. Cloud passwords are not copied to the node.

`POST /api/v1/auth/node-staff/login` accepts the assigned user's email and local password. Outside local and testing environments it requires HTTPS, as do authenticated node API requests. Provision a certificate trusted by staff devices and configure trusted TLS proxies where used. Tokens expire after 12 hours. The node rechecks current imported assignments on authenticated requests, and a newer staged snapshot makes the older assignment unavailable until refresh. The existing mobile app does not yet offer node-specific sign-in or pairing. Admission endpoints remain disabled. Before enabling them, enforce token ability and event-scoped authorization inside the admission transaction; a token alone does not grant admission authority.

The runtime identity is retained in the database. Switching the runtime of an existing database or restoring it as another node requires a deliberate, offline migration procedure, which is not implemented yet. Do not delete or edit the identity row to bypass this restriction. Database migrations must be applied before serving requests. `/up` is a process health endpoint and does not check this binding.

## 1. Core architecture

Ingress will separate online ticketing from event-day admission.

```text
Before the event

Student app
    |
    | Internet
    v
Ingress cloud
- verified student account activation and authentication
- event discovery
- student registration
- guest/alumni email ticket issuance
- ticket transfer
- ticket issuance
        |
        | finalized event snapshot
        v
Ingress Event Node


During the event

Student app                    Staff scanner
offline ticket                     |
    |                               | venue LAN
    | static opaque QR              v
    +--------------------------> Event Node
                                  |
                                  | PostgreSQL
                                  v
                   preview / confirm / entry / exit / reject


After the event

Event Node
    |
    | Internet
    v
Ingress cloud
- admissions
- walk-ins
- attendance
- audit history
```

The public internet is not part of the critical admission path. The venue LAN and Event Node are.

## 2. Authority rules

Ingress must never allow the cloud and Event Node to independently change the same event admission state during a network partition.

The authority changes by event phase:

| Phase | Authority |
| --- | --- |
| Registration open | Cloud |
| Registration closed, before finalization | Cloud while the Event Node prepares the final snapshot |
| Admission finalized / event day | Event Node for admission and walk-ins |
| After event reconciliation | Cloud becomes the long-term system of record again |

The organizer performs an explicit **Prepare for Admission** or **Finalize Admission** action. Finalization must:

1. confirm online registration is closed;
2. synchronize the final preregistered attendee and ticket snapshot to the Event Node;
3. verify that the snapshot completed successfully;
4. record a snapshot/version identifier;
5. mark the Event Node ready for admission.

Once finalized, ordinary cloud registration and self-service ticket transfer for that event are closed. New attendees use the walk-in process instead.

This rule prevents split-brain cases where the cloud says a ticket is valid but an offline Event Node has never received it.

## 3. Student authentication and offline ticket access

Student authentication is internet/cloud based.

### Institutional student account activation

Normal self-service accounts are intended for MSEUF students. The cloud sends a single-use verification link to an `@student.mseuf.edu.ph` address and completes the account only after the recipient proves control of that address. The server assigns the student role; public users never choose a privileged role. Directory ingestion is not required for this flow. Email control does not by itself prove that a student number belongs to the account holder or that enrollment is current; those claims require a separate institutional source.

The mobile sign-in screen can request a link. Opening the email link shows a web form; submitting it consumes the token and creates the account. Links expire after 30 minutes, and a new request invalidates the previous link. The link token is in the URL fragment rather than the server request path. Only its hash is stored in the database. The form uses a CSRF-protected POST; opening the link alone does not create an account.

For cloud deployment, set `APP_URL` to the public HTTPS origin, configure a real mail transport and sender (for SMTP set `MAIL_SCHEME=smtps`) with `MAIL_MAILER` and `MAIL_FROM_ADDRESS`, apply migrations, and configure trusted TLS proxies if applicable. Outside local and testing environments, account verification refuses HTTP, a non-HTTPS `APP_URL`, log or array mail transports, and SMTP without `smtps`. Delivery failures are logged without the email address or link; the request response remains generic. Configure mail monitoring and retention for expired verification records. Existing directory imports are not required for signup.

This keeps the recurring student experience account-based while preserving the MSEUF-first scope of the system. Faculty, staff, and organizers who need privileged access are provisioned through authorized administrative workflows rather than public signup.

External guests and alumni do not need a normal MSEUF account merely to attend an event. They may receive an organizer-issued or paid guest ticket by email, or be registered as a walk-in when the event permits it.

A student must successfully authenticate online on a device before that device can restore the student's session offline. Ingress will not attempt to verify a password locally.

On Android and iOS, the app caches the owned wallet in device secure storage after registration or an online wallet refresh. Web has no persistent offline wallet. The cache is bound to the signed-in account, session token, and server, and it is cleared on logout, server change, or a confirmed 401. A storage failure leaves the wallet available online only. The cache contains:

- student identity needed by the UI;
- owned ticket metadata;
- the static opaque ticket credential needed to render the QR;
- event title, venue, and schedule;
- last successful synchronization time.

When the cloud is unreachable, a previously authenticated student can open an **offline read-only session** and view cached tickets. The app shows the last full wallet refresh time. A cached ticket may have been revoked or its event cancelled since that refresh; the Event Node makes the admission decision. Offline wallet behavior has not yet been verified on physical devices.

Allowed offline:

- view cached profile information;
- view cached tickets;
- display the ticket QR;
- view cached event details.

Not allowed offline:

- register for a new event;
- transfer a ticket;
- change account credentials;
- fetch a ticket that was never synchronized to the device.

A server response of `401 Unauthorized` is different from a transport failure. A confirmed 401 invalidates the local session and sensitive cached ticket material. A network failure does not automatically log out a previously authenticated student.

Logging out manually also clears sensitive cached ticket credentials.

## 4. No Event Node configuration on the student side

Students do not configure LAN addresses, Event Node IPs, or server URLs.

The student app uses the configured cloud API for normal online operations and cached tickets when offline.

The current 50% prototype exposes a server URL screen to all mobile roles because one server handles both sides. That is prototype behavior and is not the target full-system UX.

## 5. Scanner connection and pairing

Staff scanners communicate with the Event Node over the venue LAN.

Normal scanner setup should not require staff to type an IP address. The Event Node will provide a **Pair Scanner** QR that can contain a short-lived setup payload such as:

```text
node identifier
Event Node URL
one-time pairing token
expiration
optional certificate fingerprint
```

The pairing credential must not contain a permanent administrator password or reusable privileged secret.

Manual Event Node address entry remains available only as a staff/admin fallback under advanced settings.

Automatic network discovery such as mDNS may be considered later, but it is not required for the first implementation because venue Wi-Fi multicast behavior can be inconsistent.

## 6. Staff authentication during event operation

Student authentication is cloud based, but scanner authentication cannot depend exclusively on public internet.

Before finalization, the Event Node receives the staff accounts, roles, assignments, and access-point permissions needed for that event.

During admission, staff authenticate against the Event Node or use a previously established Event Node session. If the public internet is unavailable but the venue LAN is working, authorized scanners must still be able to operate.

The Event Node remains responsible for role and gate authorization. Client-side role checks are navigation only.

## 7. Ticket credential design

Ingress will keep the existing static opaque credential model and will **not add TOTP** for the planned full system.

Canonical ticket payload:

```text
ingress:v1:<ticket_public_id>:<random_secret>
```

Reasons for keeping a static opaque credential:

- both preregistered mobile tickets and locally issued walk-in passes can use the same validation pipeline;
- the Event Node already provides centralized one-time consumption and anti-passback across entrances;
- TOTP would add clock handling, rotating QR logic, secret synchronization, and a second ticket behavior without eliminating immediate screenshot sharing;
- the scanner does not need to store ticket secrets or validate tickets independently.

The QR contains no student PII.

A copied QR cannot create multiple admissions because the Event Node atomically consumes the ticket once. A copied credential used by another person before the real owner is an identity problem, not an anti-passback problem. Ingress handles that through identity-linked tickets and configurable verification.

## 8. Identity-linked admission

Every ticket is linked to a current attendee identity.

The scanner response may show the identity required by the event policy, for example:

```text
VALID TICKET

Ron Vincent Cada
A23-35524
CCMS
```

Possession of a QR credential does not automatically prove identity.

The canonical scanner rule is:

> Scanning validates the ticket. Only an explicit authorized staff confirmation records the attendance movement.

The read-only preview shows the attendee identity and current ticket state. For an entry, exit, or permitted re-entry, the server revalidates the ticket transactionally when staff confirms the action. A preview by itself must never change attendance state.

Events may select a verification level:

### Standard
- valid ticket credential;
- attendee name;
- student number or institutional identifier.

### Identity check
- everything in Standard;
- staff confirmation against a school ID or other approved identification.

### Enhanced
- everything above;
- attendee photo available to authorized admission staff when the event has a justified need for it.

Photo collection is optional, not a default requirement. Events that collect photos must define who can view them, how long they are retained, and when they are deleted.

## 9. Registration deadline and admission finalization

Events have a scheduled registration deadline. An organizer can also close registration explicitly on a published event. That cutoff is irreversible through the current UI and does not finalize admission or transfer authority. Existing tickets remain active, and the event can still be cancelled. Planned finalization must close registration before Event Node admission begins.

Before the deadline, students use the cloud to register normally.

After the deadline:

- online registration is closed;
- self-service ticket transfer is closed after finalization;
- the final ticket snapshot is synchronized to the Event Node;
- attendees without a preregistered ticket use the walk-in process.

This is a business rule, not merely a response to an internet outage. A student who missed the deadline is a walk-in even if their phone still has internet.

## 10. Walk-in registration

Walk-ins are a first-class registration source, not an anonymous attendance counter.

Suggested source values:

```text
student_registration
guest_purchase
organizer_invitation
walk_in
```

The source describes how the attendee received the ticket. It does not change the credential or scanner protocol.

A walk-in flow should:

1. search or identify the attendee;
2. check that the person does not already have an active registration/ticket for the event;
3. check remaining event capacity under the same concurrency guarantees used elsewhere;
4. optionally capture a photo when the event uses Enhanced verification;
5. create the walk-in registration and ticket locally on the Event Node;
6. issue a printed pass;
7. admit the attendee only when the pass is scanned, unless an authorized Register & Admit workflow is explicitly used.

A normal printed walk-in pass contains:

- event name;
- opaque QR credential;
- human-readable fallback code;
- attendee name;
- student/institutional identifier when applicable;
- WALK-IN label.

The QR itself should still contain only an opaque credential.

Example:

```text
+-----------------------------+
| MSEUF INGRESS               |
| University Intramurals      |
|                             |
|         [ QR CODE ]         |
|                             |
| Code: WK-82F7-QP            |
| Ron Vincent Cada            |
| A23-35524                   |
| WALK-IN                     |
+-----------------------------+
```

The readable code is a fallback for manual entry if the camera cannot read the QR.

Walk-in ticket creation and admission are separate operations by default so registration desks and gates can be physically separate while preserving the same audit trail as preregistered tickets.

## 11. Ticket transfer

Ticket transfer is planned for the second half because users commonly give a ticket to another person when they can no longer attend.

A transfer changes ownership. It is not QR forwarding.

Recommended flow:

```text
Current owner
    |
    | request transfer to student number/email
    v
Pending transfer
    |
    | recipient accepts
    v
Cloud transaction
- change ticket owner
- rotate ticket credential
- invalidate previous credential
- record transfer history
```

Rules:

- the recipient must accept the transfer;
- until acceptance, the current owner keeps ownership;
- a completed transfer generates a new credential so the old owner's saved QR becomes invalid;
- transfer history is retained for organizer audit;
- scanner staff see the current owner, not the full transfer history;
- transfers may be disabled, limited, or require organizer approval per event;
- self-service transfers stop when the event is finalized for admission.

A transferred ticket must use the recipient's identity and, when applicable, the recipient's photo.

Walk-in passes do not need self-service transfer support.

## 12. Synchronization model

### Cloud to Event Node
Before finalization, synchronize at minimum:

- event metadata and lifecycle state;
- capacity;
- preregistered attendees;
- tickets and verification material;
- current ticket owner identity;
- staff accounts and assignments;
- venues/access points;
- event verification policy.

The Event Node records the finalized snapshot/version.

### Event Node to cloud
After or during connectivity restoration, synchronize:

- attendance movements (entry, exit, and re-entry);
- walk-in registrations and tickets;
- guest/alumni ticket issuance and attendee metadata;
- walk-in identity/photo metadata where policy permits;
- audit events;
- attendance totals;
- local ticket revocations or exceptional staff actions.

Writes must use stable identifiers and idempotent synchronization so a retry cannot create duplicate admissions or walk-ins.

## 13. Capacity rules

Capacity remains authoritative and concurrency safe.

Before finalization, cloud registration must enforce:

```text
confirmed online registrations <= event capacity
```

After finalization, the Event Node becomes responsible for remaining capacity when issuing walk-in tickets.

For example:

```text
capacity                500
final preregistration   430
walk-ins issued          25
remaining                45
```

Two walk-in desks must not be able to oversubscribe the same remaining capacity.

## 14. Explicit failure behavior

### Student has no internet
Previously synchronized ticket remains viewable offline.

### Student never synchronized the ticket
The app cannot invent a ticket. The attendee needs connectivity or event staff assistance.

### Student reinstalls the app at the venue
Ticket recovery requires connectivity to the cloud unless a separate recovery process is introduced later.

### Cloud is unavailable during admission
Scanning and walk-in registration continue as long as the Event Node and venue LAN are operating.

### Event Node is unavailable
Admission fails closed. This is an accepted tradeoff for immediate shared anti-passback state.

Operational mitigation can include:

- stable dedicated access points;
- wired backhaul where practical;
- reliable Event Node hardware;
- UPS or backup power;
- database backups;
- documented spare-node recovery;
- an organizer-approved emergency procedure.

### Venue LAN is unavailable
Scanners cannot safely share admission state, so normal scanning stops.

## 15. Security claims Ingress should make

Use these claims:

> Ingress removes public internet from the critical admission path. Student tickets can be displayed offline, while scanners validate them against one local Event Node over the venue LAN.

> Ingress uses opaque, cryptographically random ticket credentials. Attendance state is server-authoritative, so a ticket cannot be used for two simultaneous entries across connected entrances. Re-entry is allowed only after a recorded exit and only when the event policy permits it.

> A ticket credential proves possession of the ticket, not identity. Events can require identity checks or photo verification when the risk justifies it.

Avoid these claims:

- "Ingress works completely offline."
- "QR screenshots are impossible."
- "Static QR prevents sharing."
- "The scanner validates tickets offline."
- "TOTP prevents screenshots."

## 16. Planned data-model additions

Exact migrations are implementation work, but the target model will likely need:

- registration/ticket source such as `student_registration`, `guest_purchase`, `organizer_invitation`, or `walk_in`;
- an attendee abstraction that can represent a verified student or an external guest without requiring every attendee to own a login account;
- attendee classification such as Envergan/student, alumni, or external guest for reporting;
- finalization/snapshot version on the event;
- Event Node synchronization state;
- walk-in issuance metadata;
- optional attendee photo reference and retention metadata;
- ticket ownership/transfer records;
- pending transfer state;
- credential rotation/versioning on transfer;
- human-readable fallback code for locally printed passes;
- guest/alumni email delivery metadata and payment/reference status where applicable;
- an append-only attendance movement model for `entry` and `exit`;
- an event re-entry policy and a concurrency-safe way to derive current inside/outside state.

Existing anti-passback and `credential_version` concepts should be reused instead of introducing a separate admission engine for walk-ins.

## 17. Implementation order

See [the roadmap](roadmap.md) for the dependency order and current status.

## 18. Guest and alumni email tickets

Ingress is MSEUF-first, so verified students keep the richer account-based experience. Alumni, invited visitors, parents, partner-school attendees, and other external guests should not be forced to create a MSEUF account for occasional attendance.

A guest ticket may be created by an organizer invitation or by a verified payment flow. The minimum attendee record should contain the event-specific identity needed for admission, for example:

```text
name
email
organization / school (optional)
attendee classification
payment/reference status when applicable
```

The cloud issues the same opaque Ingress ticket credential and delivers it by email as a QR ticket or printable attachment. The recipient does not need the Ingress mobile app and does not need a TOTP secret.

This is one reason Ingress keeps server-authoritative static opaque credentials: a ticket can be shown from the student wallet, an email, or a printed pass while the Event Node uses the same validation pipeline.

Guest and alumni tickets must be distinguishable from verified MSEUF student tickets in reporting. The classification comes from the attendee/ticket record, not by guessing from the email domain.

Payment integration is cloud-side and must create or activate a guest ticket only after the payment provider reports a verified successful payment. The exact payment provider is an implementation choice and is not required for the admission protocol.

## 19. Entry, exit, and re-entry tracking

The first-half system records one admission and rejects every later entry scan. The full system should evolve this into an append-only attendance movement history so organizers can tell who is currently inside, who has left, and who has re-entered.

Conceptual state machine:

```text
OUTSIDE --ENTRY--> INSIDE
INSIDE  --EXIT----> OUTSIDE
OUTSIDE --ENTRY--> INSIDE   (re-entry, only when policy allows)
```

Rules:

- an `ENTRY` while already `INSIDE` is rejected as duplicate/passback;
- an `EXIT` while already `OUTSIDE` is rejected as invalid state;
- after a recorded exit, another entry is allowed only when the event permits re-entry;
- entry/exit state transitions must lock the ticket or attendee presence state so two access points cannot create contradictory movements;
- a scan preview remains read-only; only explicit staff confirmation records the movement;
- re-entry never consumes another capacity slot because capacity counts issued/registered attendees, not movement rows.

The event should expose a simple re-entry policy:

```text
re_entry_allowed = true | false
```

The dashboard can then distinguish:

- total registered/issued attendees;
- attendees who have entered at least once;
- currently inside;
- currently outside after a prior entry;
- re-entry count;
- Envergans currently inside;
- external guests currently inside.

The movement history should preserve the access point, staff member, timestamp, movement direction, and idempotency identifier for audit.

## 20. Non-goals

The second-half architecture does not require:

- offline independent scanner validation;
- distributing all ticket secrets to scanner phones;
- TOTP or rotating QR codes;
- PostgreSQL logical replication;
- Kafka or another message broker;
- Kubernetes;
- automatic peer-to-peer scanner synchronization.

The design intentionally keeps one local admission authority so duplicate admission is rejected before a second person enters.
