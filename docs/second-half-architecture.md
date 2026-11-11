# Ingress architecture and operating limits

**Status:** The cloud and Event Node architecture is implemented. The mobile app is now a staff-only Event Node scanner; student accounts, registration, ticket access, and transfers use the website. Safe authority recovery, automatic snapshot delivery, data-retention automation, and real payment-provider integration remain unimplemented. Paid checkout is simulated. Device and venue verification is deferred to the project owner.
**Current implementation:** Cloud accounts, web registration and ticket transfers, organizer reporting, snapshot transfer, Event Node admission, guest and alumni passes, node walk-ins, entry and exit tracking, and retryable admission synchronization are implemented. Students with a verified institutional email can transfer eligible tickets before the event ends and before snapshot preparation. Accountless guest and alumni ticket holders can use a one-use redemption code to transfer an eligible public ticket while those same event limits allow it. Email activation verifies control of the institutional address, not current enrollment. Attendees can download ticket PDFs from the website; a saved PDF can display its static QR without internet. Admission requires the venue LAN and Event Node, but not public internet.

The native app contains no student sign-in, event discovery, ticket wallet, or Cloud API client. Its EAS `preview` and `production` profiles use Android system certificate trust. The separate `lanDemo` profile trusts one supplied public CA for one exact Event Node hostname or IPv4 address and still requires HTTPS. See [Staff scanner build instructions](../mobile/README.md).

## Runtime boundaries

`INGRESS_RUNTIME=legacy` retains the single-server demo only when `APP_ENV` is `local` or `testing`. Cloud and node runtimes require PostgreSQL outside automated tests. Set a unique, stable `INGRESS_NODE_ID` on each node. Each database binds to the first runtime and node identity that uses it; a different identity receives HTTP 503. Configure separate PostgreSQL databases for cloud and each node. This binding prevents an accidental shared database, but it does not authenticate a node or prevent copies of a database from running independently.

Cloud permits student registration and organizer event changes, but rejects scanner endpoints. The node rejects ordinary attendee registration and organizer operations; assigned staff can issue walk ins after authority activation. An assigned staff member can preview and confirm admission on a node only after activating the signed authority grant. Node admissions synchronize to the cloud in authenticated, retryable batches.

On the cloud, an organizer can prepare a versioned snapshot after registration closes. Preparation locks the event while it captures event details, gates, staff assignments, registrations, and ticket verification hashes. The snapshot is encrypted with the cloud application key at rest. It contains neither QR secrets nor account password hashes. Invalidated snapshots still contain encrypted identity data; automatic retention and deletion are not implemented. Editing the event, gates, or staff assignments requires discarding the prepared snapshot; cancellation invalidates it. Concurrent preparation returns the same snapshot version. A later preparation after discard creates a new version.

A prepared snapshot can be transferred as an encrypted file to one registered node. The cloud encrypts and authenticates the package with that node's transfer key. The node verifies the package, stages the payload encrypted with its own application key, and produces an authenticated receipt. The cloud verifies that receipt against the current snapshot and target node. Repeating receipt is idempotent. A node rejects an older version after receiving a newer one and rejects conflicting snapshots for the same event and version.

This is a manual staging workflow. A receipt proves possession of the node transfer key and records the receiver's claim that it staged the package; it is not hardware attestation. Preparation and staging validate the snapshot schema, roster references, ticket credentials, and capacity. Events with existing admissions cannot be prepared because admission history is not included. An operator can import the current staged version into node event, roster, gate, and ticket records. The import maps cloud IDs to node-local IDs, uses random passwords for imported accounts, and is transactional and idempotent. A separate authenticated import receipt records the node's claim that the current snapshot was imported. It does not provision staff credentials, finalize admission, or transfer authority. A newer staged snapshot can replace the node import before authority activation. The refresh locks the event, rejects any existing admissions or records outside the previous import, and rolls back on conflicts. It preserves the node event ID but may change node-local gate, staff, registration, and ticket IDs. Identity or venue conflicts stop the import without changing the event. Removed users and prior encrypted snapshots remain on the node until a retention process is implemented. Imported records contain personal data in the node database and require the same access controls and storage protection as the cloud database. The node can admit attendees after authority activation. Automatic delivery, retention automation, and safe recovery after grant issuance are not implemented. After both receipts, the cloud can issue a signed authority grant for exactly one snapshot and node. Issuing it locks cloud cancellation and snapshot discard. The node accepts the grant only for its current imported snapshot and refuses later snapshot replacement. The admission transaction rechecks the signed grant, current staff session, event assignment, and gate before recording a movement. Any future user identity, staff credential, or ticket revocation changes must participate in the same locking and snapshot invalidation rules.

### Stage a snapshot on a node

1. On the cloud host, set `INGRESS_RUNTIME=cloud`, configure PostgreSQL and a cloud `APP_KEY`, and apply migrations. Run `php artisan ingress:register-node NODE_ID`. The command prints a random transfer secret once. Treat it as a password, keep it out of logs and shell history, and transfer it to the node through a secure operator channel.
2. On the node, configure a separate PostgreSQL database and independent `APP_KEY`, set `INGRESS_RUNTIME=node`, `INGRESS_NODE_ID` to the registered ID, and `INGRESS_NODE_SYNC_KEY` to that secret. Apply migrations and refresh cached configuration after changing it.
3. In the cloud organizer event page, close registration and prepare a snapshot. Enter the node ID and download the encrypted package. Outside local and testing environments the export and receipt endpoints require HTTPS. If TLS terminates at a reverse proxy, configure Laravel to trust only that proxy so it can recognize the secure request.
4. Copy the package to the node and run `php artisan ingress:receive-snapshot PATH`. The command stages it and prints a receipt. Return that receipt to the cloud organizer page. Re-importing the same current version after a restart returns the same receipt.
5. Run `php artisan ingress:import-snapshot CLOUD_EVENT_ID` on the node. It imports the current staged version and prints the node event ID and an import receipt. Confirm the staging receipt first, then return the import receipt to the cloud organizer page. The import receipt is authenticated with a separate purpose from the staging receipt and is accepted only for the current snapshot and active target node. It records a claim made with the node transfer key, not hardware attestation or proof against a compromised node. Admission remains disabled until the authority grant is activated. Repeating the command for the same snapshot is safe. To refresh, discard and prepare a new snapshot on the cloud, transfer and stage it, then run the import command again. Refresh is refused if admissions or untracked event records exist. It does not fence copied nodes or transfer authority.
6. To disable a node's transfer credential on the cloud, run `php artisan ingress:revoke-node NODE_ID`. Revocation removes the cloud copy of the key and the ID cannot be registered again. It does not remotely erase a package already staged on an offline node. Before issuing an authority grant, an operator can use a replacement node by discarding the provisional snapshot, preparing a new version, and registering a new ID. After a grant, replacement requires a safe recovery procedure that is not yet implemented.

### Transfer authority

Cloud and nodes require PHP's sodium extension. Generate a dedicated Ed25519 key pair in a secure cloud environment, for example with `php -r '$p = sodium_crypto_sign_keypair(); echo "private=".base64_encode(sodium_crypto_sign_secretkey($p)).PHP_EOL."public=".base64_encode(sodium_crypto_sign_publickey($p)).PHP_EOL;'`. Set `INGRESS_AUTHORITY_PRIVATE_KEY` and `INGRESS_AUTHORITY_PUBLIC_KEY` on the cloud. Set only `INGRESS_AUTHORITY_PUBLIC_KEY` on each node through a trusted provisioning channel. Keep the private key out of node machines and backups accessible to node operators. A key mismatch fails before cloud authority changes; an incorrectly provisioned node public key prevents activation. Keep the signing key pair and node public key stable until all issued grants have been activated.

After both receipts are confirmed, the organizer can download a signed authority grant. The cloud records the transfer before returning the grant, so a lost download can be retried. Copy the grant to the intended node and run `php artisan ingress:activate-event PATH`. Both operations are idempotent. Issuing the grant locks cloud cancellation and snapshot changes even if the node never receives it. Assigned staff can scan after activation. There is no safe automatic authority recovery yet.

Use exactly one node database and one live instance for an event. Do not clone or restore an activated node database, or restore cloud state to before a grant, while the original node or its grant may still be used. An offline system cannot detect those copies. Revoking a node transfer key prevents future transfers but cannot revoke a grant already issued or reach an offline node. Do not replace a node until an explicit recovery process can prove the old authority is retired.

A package is bound to the first target node selected for its snapshot. The limit is 16 MiB per encrypted package. Invalidated snapshots and staged payloads retain encrypted identity data until an operator removes them under a future retention procedure.

### Synchronize admissions

Set `INGRESS_CLOUD_SYNC_URL` on the node to the cloud HTTPS API base; for current staging, use `https://ingress-pp2u.onrender.com/api/v1`. Install a certificate chain trusted by the node. Configure Laravel's scheduler to run `php artisan schedule:run` every minute. On Windows, use the Task Scheduler setup in [Deployment and Operations](deployment.md#run-the-node-synchronization-scheduler). On a node with this URL configured, it runs `ingress:sync-admissions` once per minute with an overlap lock. An operator can also run `php artisan ingress:sync-admissions CLOUD_EVENT_ID`, or omit the ID to process all activated events.

The node keeps each encrypted batch until it receives an authenticated cloud receipt. It retries the same batch after a lost connection or response and marks only the acknowledged admissions as synchronized. New admissions can continue during an upload and are sent in later batches. Each run sends at most 40 walk-ins, ten recovery records, and ten batches of 100 admissions per event. The command stops starting new uploads after eight minutes and resumes pending work on the next scheduled run. The scheduler lock lasts ten minutes. A database operation that blocks beyond that window can still extend the run. The cloud checks the active authority, frozen snapshot, ticket, gate, staff assignment, and idempotency identifiers before recording a batch in one transaction. A duplicate or rebatch of the same admission is safe; conflicting records stop the batch. Sync failures are recorded in the node application log. Cloud reports may lag while the node is offline.

Imported preregistered student, guest, and alumni tickets are supported by this synchronization protocol. Node walk-ins use a separate authenticated receipt to establish their cloud ticket mapping before admission batches reference them. Admission movements synchronize in authenticated batches; safe authority recovery remains future work. Revoking a node transfer credential also prevents pending admissions from synchronizing. There is no credential recovery or rekey procedure yet, so retain the node database and investigate failed batches rather than deleting them or reassigning authority.

### Provision node staff credentials

After importing a current snapshot, an operator with local node access can run `php artisan ingress:list-node-staff` to see assigned staff and their cloud user IDs. Run `php artisan ingress:provision-node-staff CLOUD_USER_ID` to issue a random local password. It is printed once; deliver it through a secure channel. Repeating the command rotates the password and revokes that user's node sessions. `php artisan ingress:revoke-node-staff CLOUD_USER_ID` revokes the local password and sessions, including after the assignment is removed. Cloud passwords are not copied to the node.

`POST /api/v1/auth/node-staff/login` accepts the assigned user's email and local password. Outside local and testing environments it requires HTTPS, as do authenticated node API requests. Provision a certificate trusted by staff devices and configure trusted TLS proxies where used. Tokens expire after 12 hours. The node rechecks current imported assignments on authenticated requests, and a newer staged snapshot makes the older assignment unavailable until refresh. The native mobile app offers separate node staff sign-in and one-use QR pairing. The scanner endpoints require a current node staff token, an event assignment, the assigned gate, and the activated authority grant. A token alone does not grant admission authority.

The runtime identity is retained in the database. Switching the runtime of an existing database or restoring it as another node requires a deliberate, offline migration procedure, which is not implemented yet. Do not delete or edit the identity row to bypass this restriction. Database migrations must be applied before serving requests. `/up` is a process health endpoint and does not check this binding.

## 1. Core architecture

Ingress will separate online ticketing from event-day admission.

```text
Before the event

Student website
    |
    | Internet
    v
Ingress cloud
- institutional-email student account activation and authentication
- event discovery
- student registration
- organizer-issued guest and alumni passes
- ticket transfer
- ticket issuance
        |
        | finalized event snapshot
        v
Ingress Event Node


During the event

Previously downloaded PDF     Staff scanner
    |                               |
    | static opaque QR              | venue LAN
    +------------------------------> Event Node
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

### Firebase student authentication

Normal self-service accounts are intended for MSEUF students. Students use Firebase Email/Password at `/student/auth`; Firebase sends verification and password-reset messages. The browser exchanges a Firebase ID token through `/student/session`. Laravel verifies the token with Firebase Identity Toolkit, checks that the email is verified and ends in `@student.mseuf.edu.ph`, and assigns the student role. Public users cannot choose a privileged role. Directory imports are not required. Email control does not by itself prove that enrollment is current.

Firebase owns the verification and reset links. The Laravel application does not issue student email-verification links or create accounts through the retired `/api/v1/auth/student-verification` and `/student/complete` routes. Firebase's authorized-domain list must include the deployed Cloud host. The operator-only staging command can create synthetic `@example.test` students, but leaves them unverified.

Render startup does not require a mail provider. Resend is optional for event-manager invitations. When configured, the invitation route requires a safe, configured transport and an HTTPS application origin; missing credentials, logging transports, and plaintext SMTP fail closed. See the [deployment guide](deployment.md#optional-resend-mail-for-event-manager-invitations) for the Render settings.

This keeps the recurring student experience account-based while preserving the MSEUF-first scope of the system. Faculty, staff, and organizers who need privileged access are provisioned through authorized administrative workflows rather than public signup.

External guests and alumni do not need a normal MSEUF account merely to attend an event. They may receive an organizer-issued or paid guest ticket by email, or be registered as a walk-in when the event permits it.

Attendees use the website for account access and ticket actions. After registration, they can download a PDF that contains the static opaque admission QR. A copy saved on the attendee's device can be displayed without internet, but it may be stale if the ticket was later transferred or revoked; the Event Node makes the admission decision. The native app no longer stores attendee sessions or tickets.

## 4. Student cloud access

Students use the Cloud website for account access, event discovery, registration, ticket PDFs, and transfers. They do not configure LAN addresses, Event Node IPs, or scanner settings. The mobile app is reserved for Event Node staff and has no Cloud API URL or attendee session storage.

## 5. Scanner connection and pairing

Staff scanners communicate with the Event Node over the venue LAN.

Normal scanner setup uses a **Pair Scanner** QR containing a short-lived setup payload:

```text
schema version
node identifier
HTTPS Event Node API URL
one-time pairing token
expiration
```

The pairing credential must not contain a permanent administrator password or reusable privileged secret.

Manual HTTPS Event Node address entry remains available to staff as a fallback. The scanner rejects HTTP in development and release builds.

The scanner accepts HTTPS only in development and release builds. Android preview and production builds use system CA trust. For a controlled demo with a private certificate, the separate `lanDemo` profile trusts a public CA only for one exact configured hostname or IPv4 address. The app keeps standard certificate and hostname validation enabled. See [Staff scanner build instructions](../mobile/README.md).

## 6. Staff authentication during event operation

Student authentication and ticket actions use the Cloud website. Scanner authentication cannot depend exclusively on public internet.

Before finalization, the Event Node receives the staff accounts, roles, assignments, and access-point permissions needed for that event.

During admission, staff authenticate against the Event Node or use a previously established Event Node session. If the public internet is unavailable but the venue LAN is working, authorized scanners must still be able to operate.

The Event Node remains responsible for role and gate authorization. Client-side role checks are navigation only.

## 7. Ticket credential design

Ingress uses static opaque ticket credentials and does not use TOTP.

Canonical ticket payload:

```text
ingress:v1:<ticket_public_id>:<random_secret>
```

Reasons for keeping a static opaque credential:

- both preregistered mobile tickets and locally issued walk-in passes can use the same validation pipeline;
- the Event Node maintains one shared movement history and enforces anti-passback across entrances;
- TOTP would add clock handling, rotating QR logic, secret synchronization, and a second ticket behavior without eliminating immediate screenshot sharing;
- the scanner does not need to store ticket secrets or validate tickets independently.

The QR contains no student PII.

A copied credential cannot create duplicate active entries because the Event Node checks each movement against the shared ticket history. A copied credential used by another person before the real owner is an identity problem, not an anti-passback problem. Events can require staff to compare the attendee with the name and identifier or confirm an approved ID check.

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

Events may select Standard identity details or an approved ID check:

### Standard
- valid ticket credential;
- attendee name;
- student number or institutional identifier.

### Identity check
- everything in Standard;
- staff confirmation against a school ID or other approved identification.

An enhanced photo check is not implemented. Photo collection stays disabled until the institution approves access, retention, and deletion rules.

## 9. Registration deadline and admission finalization

Events have a scheduled registration deadline, and an organizer can close registration explicitly. Snapshot preparation requires registration to be closed. The organizer can discard a prepared snapshot and create a new version before issuing an authority grant. Once the cloud issues the grant, cancellation and snapshot changes are locked. Existing tickets remain active through the handoff.

Before the deadline, students use the cloud to register normally.

After the deadline:

- online registration is closed;
- self-service ticket transfer is closed after finalization;
- the organizer transfers the final encrypted ticket snapshot to the Event Node and confirms staging and import receipts;
- attendees without a preregistered ticket use the walk-in process.

This is a business rule, not merely a response to an internet outage. A student who missed the deadline is a walk-in even if their phone still has internet.

### Organizer-issued guest and alumni passes

Before registration closes, an authorized organizer can enter a guest or alumni name and email on the event page and issue a pass. The attendee does not need a user account. The system does not verify the address or send the pass automatically. Guest and alumni duplicates are checked against other accountless registrations and active guest checkout reservations by normalized contact email. A guest contact address does not block a separate student account registration with the same email. The organizer opens the protected pass page and prints or securely delivers it. Rendering the pass requires PHP XMLWriter. The page uses no-store headers and does not put the QR credential in its URL. Reprinting is available while the ticket is active and the event is not cancelled.

Guest and alumni registrations share the event capacity lock with student registrations. Their opaque QR uses the same validation and admission path. Standard events keep the prior snapshot schema for compatibility. Schema version 4 carries ID-check policy; both cloud and node must be upgraded before transferring an event that requires ID checks. Guest and alumni admissions synchronize back to the cloud using the same batch protocol.

## 10. Walk-in registration

Walk-ins are a first-class registration source, not an anonymous attendance counter.

The node staff app can issue a student, guest, or alumni walk in pass for an assigned event with active node authority. A student ID is self reported, not institutionally verified. The node checks email and student ID against existing event registrations and locks the event while enforcing capacity. The app stores the pending request in device-only secure storage before sending it, so a lost response can be retried with the same identifier. Assigned staff can recover an active pass by attendee email on the node. The node records the staff member and recovery time, then synchronizes that audit record after issuance. Staff can display or print the QR; issuance and recovery do not admit the attendee. Physical printer and device verification remain pending.

The node sends each walk-in in an encrypted package and retains it until the cloud returns an authenticated receipt. The cloud stores the ticket hash without the QR secret. The node then maps the cloud registration and ticket IDs and sends any admission through the regular admission sync. A conflicting identity or capacity failure leaves that walk-in pending for investigation. Admissions for tickets that already have cloud mappings can still synchronize. The printed pass includes the complete private QR payload for manual entry. The public ticket reference alone cannot authenticate a scan.

The implementation stores attendee type and walk-in kind separately. A walk in uses the same credential and scanner protocol as a preregistered ticket.

A walk-in flow:

1. search or identify the attendee;
2. check that the person does not already have an active registration/ticket for the event;
3. check remaining event capacity under the same concurrency guarantees used elsewhere;
4. create the walk-in registration and ticket locally on the Event Node;
5. issue a printable pass;
6. admit the attendee only when the pass is scanned, unless an authorized Register & Admit workflow is explicitly used.

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

The cloud supports ticket transfer only before the event end and before snapshot preparation. Events opt in; new events default to disabled. Student-account transfers require verified institutional email and recipient acceptance. The email check does not prove current enrollment. Closing registration alone does not close transfers.

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
- an event may disable transfers; this version does not impose a transfer count limit or organizer approval step;
- self-service requests and acceptance stop at the event end or when the event is finalized for admission.

A request appears in the recipient's web account ticket list. The sender can cancel a pending request, and the recipient can accept or decline it. After the event ends, a pending request cannot be accepted, but it remains cancellable by the sender or declinable by the recipient. Disabling transfers expires pending requests. Snapshot preparation also expires pending requests while holding the event lock, so acceptance and finalization serialize. A transferred ticket uses the recipient account's name, email, and student number. The current account model has no attendee photo field.

The organizer event page paginates transfer history at 100 requests per page and shows sender, recipient, ticket reference, status, and transition timestamps. Scanner staff see only the current ticket owner.

Walk-in passes do not need self-service transfer support.

### Accountless guest and alumni redemption

An eligible guest or alumni ticket can be transferred without creating an account. The recipient submits the private redemption code, their name, and a contact email at `/tickets/redeem`. The flow is limited to confirmed accountless registrations in public events and public ticket categories, with transfers enabled, before event end and snapshot preparation, and before admission. Student-restricted tickets and node walk-ins are rejected.

The cloud locks the event, ticket, registration, and category, validates the current code and transfer eligibility, replaces the attendee name and contact email, then rotates the admission and redemption credentials in one transaction. A failed rotation rolls back the identity change. The old credentials stop working after commit. The rotation audit records the redemption and credential-version transition without retaining the submitted contact details. Pass and PDF views use the current registration identity. Snapshot serialization uses the same current attendee details, and scanner preview shows the current attendee. Under an event's identity-check policy, staff compare that name with an approved photo ID before admission; the system does not verify the redeemer's identity or store ID documents. Code possession establishes ticket control only. The recipient email is contact information, not verified identity. It is checked against other accountless registrations and active guest checkouts, not against student account emails. No student number or account is collected.

## 12. Synchronization model

### Cloud to Event Node
After registration closes, the organizer stages a versioned encrypted snapshot on one registered node and returns its staging and import receipts to the cloud. The snapshot contains:

- event metadata and lifecycle state;
- capacity and registration cutoff;
- preregistered student, guest, and alumni attendees;
- ticket hashes and current ticket owner identity;
- staff assignments and access points;
- re-entry and identity verification policies.

The Event Node records the finalized snapshot/version.

### Event Node to cloud
The node retains records until the cloud returns an authenticated receipt. It synchronizes:

- attendance movements (entry, exit, and re-entry);
- walk-in registrations and tickets through authenticated issuance receipts;
- walk-in recovery records;
- entry and exit movements in authenticated admission batches.

The cloud derives attendance totals from imported movements. Stable identifiers and authenticated receipts make retries idempotent. Movement history and authority recovery are not transferred as general audit events.

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
The attendee can display a previously downloaded ticket PDF, but the website cannot perform online account or registration actions.

### Student never synchronized the ticket
The attendee needs connectivity to open the website and download the ticket or event staff assistance.

### Student reinstalls the app at the venue
There is no attendee app to reinstall. Assigned staff can recover an active walk-in pass on the authoritative Event Node without public internet, but they must verify the attendee in person under the event's approved identity procedure. Email alone is not proof of identity.

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

> Ingress removes public internet from the critical admission path. Attendees can display a previously downloaded ticket PDF offline, while the staff scanner validates it against one local Event Node over the venue LAN.

> Ingress uses opaque, cryptographically random ticket credentials. Attendance state is server-authoritative, so a ticket cannot be used for two simultaneous entries across connected entrances. Re-entry is allowed only after a recorded exit and only when the event policy permits it.

> A ticket credential proves possession of the ticket, not identity. Events can require staff to acknowledge checking an approved ID. The app does not collect or compare photos.

Avoid these claims:

- "Ingress works completely offline."
- "QR screenshots are impossible."
- "Static QR prevents sharing."
- "The scanner validates tickets offline."
- "TOTP prevents screenshots."

## 16. Current data model and deferred records

The current schema stores event identity and re-entry policies, student and accountless guest registrations, ticket credential versions, ticket transfers, versioned event snapshots, node import receipts, append-only admission movements, and synchronization receipts. Walk-in records retain their issue and recovery details. The admission movement stores the staff acknowledgment for an event's approved ID check.

The schema does not store attendee photos or photo-retention metadata. Paid guest tickets and email delivery are not implemented. Safe recovery or authority transfer after a node grant is also not implemented.

## 17. Implementation order

See [the roadmap](roadmap.md) for the dependency order and current status.

## 18. Accountless guest and alumni passes

Organizers can issue guest and alumni passes before registration closes. The attendee needs no MSEUF account. The organizer prints or securely delivers the pass from its protected page. The app does not verify the email address or send the pass automatically. Eligible public guest and alumni tickets can later be transferred through the accountless redemption flow described in section 11. Each recipient replaces the current name and contact email while the service rotates both ticket credentials.

The attendee type is stored with the registration, so the organizer report can distinguish student accounts, guests, and alumni without inferring identity from an email domain. Guest and alumni tickets use the same opaque credential and Event Node validation pipeline as student tickets.

Node staff can issue student, guest, or alumni walk-in passes after authority activation. The node checks capacity and existing event registrations before creating the pass. A student number entered for a walk-in remains self reported.

Assigned staff can recover an active walk-in pass on the Event Node while the venue LAN is available, including when public internet is down. Staff must verify the attendee in person under the event's approved identity procedure, then select `lost`, `damaged or unreadable`, or `pass not received`. The recovery stores the staff member, time, attestation, and reason on the node and syncs them to the organizer history when cloud connectivity returns. The returned QR is a bearer credential, so staff must hand it directly to the attendee. Do not collect identity document numbers or copies. If identity cannot be verified, do not recover the pass. Recovery does not admit the attendee; admission still requires the normal scan and confirmation.

Paid ticket flows, automatic guest email delivery, and photo collection are not implemented. Firebase email verification proves control of the institutional address, not student number ownership or current enrollment.

## 19. Entry, exit, and re-entry tracking

Each confirmed scan appends an `entry` or `exit` movement to `admissions`. The latest movement for a ticket determines whether its attendee is inside. Existing admission rows migrate to `entry` so past check-ins keep their meaning.

Conceptual state machine:

```text
OUTSIDE --ENTRY--> INSIDE
INSIDE  --EXIT----> OUTSIDE
OUTSIDE --ENTRY--> INSIDE   (re-entry, only when policy allows)
```

The Event Node locks the ticket before checking and writing a movement. The scanner selects a direction, receives a read-only preview, and records the transition only after staff confirms it. An `entry` while inside and an `exit` while outside are rejected. A later `entry` after exit is accepted only when `events.re_entry_allowed` is true. The default is false, and re-entry does not change registration capacity.

The re-entry policy is frozen into snapshot schema version 3 when enabled. Versions 1 and 2 import with re-entry disabled. Version 4 adds identity policy and carries the current re-entry setting.

The event should expose a simple re-entry policy:

```text
re_entry_allowed = true | false
```

The organizer dashboard derives these values from confirmed registrations and each ticket's latest movement:

- total registered/issued attendees;
- attendees who have ever entered and attendees not yet arrived;
- attendees currently inside and attendees currently outside after entry;
- entry movements after the first entry;
- student accounts currently inside;
- guests and alumni currently inside.

Each movement preserves the access point, staff member, timestamp, direction, ID-check acknowledgment, and idempotency identifier. The organizer can download a private CSV containing the confirmed roster and latest movement details. Keep exported personal data in an approved storage location.

## 20. Non-goals and remaining limits

Ingress does not require:

- offline independent scanner validation;
- distributing all ticket secrets to scanner phones;
- TOTP or rotating QR codes;
- PostgreSQL logical replication;
- Kafka or another message broker;
- Kubernetes;
- automatic peer-to-peer scanner synchronization.

The design intentionally keeps one local admission authority so duplicate admission is rejected before a second person enters.

### Pair a staff scanner

After provisioning an assigned staff member's local credential, run `php artisan ingress:pair-node-staff CLOUD_USER_ID --url=https://node.example/api/v1` on the node. Show the terminal QR only to that staff member. It contains the node ID, API address, and a random one-use code that expires after three minutes. It does not contain the staff password or node transfer key. Issuing a new code, rotating the credential, or revoking it invalidates older unused codes. A terminal that supports ANSI colors and block characters is needed to display the QR.

In the native app, select **Scan pairing code**, scan the operator's QR, and confirm the displayed node ID and address with the operator before pairing. The client requires HTTPS with normal certificate and hostname validation, and rejects redirects. The `lanDemo` build profile can add a dedicated public CA for one exact node host; it does not permit HTTP. The node checks the current assignment and local credential when exchanging the code for a 12-hour node staff session. The server enforces expiry. If the exchange response is lost, request a new code because the first may already be consumed. A QR code is a bearer credential: anyone who sees it before use can redeem it, so do not photograph or share it. The node ID shown by the app is a confirmation aid, not hardware attestation or certificate pinning.

## Node staff mobile session

Staff can enter a trusted node API address and authenticate with a node-local credential. All addresses use HTTPS. The address and token are stored in native secure storage only after successful authentication; passwords are not persisted. Requests omit cookies and reject redirects. A saved session must be resumed against the node on the venue LAN. Node and Cloud sessions are independent; the mobile app stores no Cloud session. If the node is unreachable at sign-out, the local token is removed but its server token can remain valid until expiry.

The scanner can pair using a one-use QR or sign in manually. The staff member must confirm the node address and identity with the operator. No Cloud profile flag grants Event Node access; the node checks its own staff assignment and session.
