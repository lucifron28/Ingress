# Ingress second-half target architecture

**Status:** Planned for the remaining 50% of the project.  
**Current implementation:** The existing 50% milestone still uses one local Laravel/PostgreSQL server for student and staff mobile traffic. This document records the agreed target architecture so later implementation does not drift.

## 1. Core architecture

Ingress will separate online ticketing from event-day admission.

```text
Before the event

Student app
    |
    | Internet
    v
Ingress cloud
- student authentication
- event discovery
- registration
- cancellation
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
                         admit / reject / duplicate


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

Once finalized, ordinary cloud registration, cancellation, and self-service ticket transfer for that event are closed. New attendees use the walk-in process instead.

This rule prevents split-brain cases where the cloud says a ticket is valid but an offline Event Node has never received it.

## 3. Student authentication and offline ticket access

Student authentication is internet/cloud based.

A student must successfully authenticate online on a device before that device can restore the student's session offline. Ingress will not attempt to verify a password locally.

After a successful online session, the student app may securely cache:

- student identity needed by the UI;
- owned ticket metadata;
- the static opaque ticket credential needed to render the QR;
- event title, venue, and schedule;
- last successful synchronization time.

When the cloud is unreachable, a previously authenticated student can open an **offline read-only session** and view cached tickets.

Allowed offline:

- view cached profile information;
- view cached tickets;
- display the ticket QR;
- view cached event details.

Not allowed offline:

- register for a new event;
- cancel a registration;
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

Events have a registration deadline before admission begins.

Before the deadline, students use the cloud to register normally.

After the deadline:

- online registration is closed;
- online cancellation is closed after finalization;
- self-service ticket transfer is closed after finalization;
- the final ticket snapshot is synchronized to the Event Node;
- attendees without a preregistered ticket use the walk-in process.

This is a business rule, not merely a response to an internet outage. A student who missed the deadline is a walk-in even if their phone still has internet.

## 10. Walk-in registration

Walk-ins are a first-class registration source, not an anonymous attendance counter.

Suggested source values:

```text
online
walk_in
```

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

- admissions;
- walk-in registrations and tickets;
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

> Ingress uses opaque, cryptographically random ticket credentials. Admission is server-authoritative, so one ticket can only be consumed once across all connected entrances.

> A ticket credential proves possession of the ticket, not identity. Events can require identity checks or photo verification when the risk justifies it.

Avoid these claims:

- "Ingress works completely offline."
- "QR screenshots are impossible."
- "Static QR prevents sharing."
- "The scanner validates tickets offline."
- "TOTP prevents screenshots."

## 16. Planned data-model additions

Exact migrations are implementation work, but the target model will likely need:

- registration source: `online` or `walk_in`;
- finalization/snapshot version on the event;
- Event Node synchronization state;
- walk-in issuance metadata;
- optional attendee photo reference and retention metadata;
- ticket ownership/transfer records;
- pending transfer state;
- credential rotation/versioning on transfer;
- human-readable fallback code for locally printed passes.

Existing anti-passback and `credential_version` concepts should be reused instead of introducing a separate admission engine for walk-ins.

## 17. Suggested second-half implementation order

1. Split student cloud API configuration from staff Event Node API configuration.
2. Remove Event Node/IP settings from the student experience.
3. Add student cached-session restoration and offline cached ticket wallet.
4. Add event registration deadline and admission-finalization state.
5. Add cloud-to-Event-Node finalized snapshot synchronization.
6. Add Event Node staff authentication/assignment availability without public internet.
7. Add scanner QR pairing with staff-only manual URL fallback.
8. Add walk-in registration, capacity enforcement, printable pass, and fallback code.
9. Add event-configurable identity verification and optional photo capture.
10. Add ticket transfer with recipient acceptance, ownership change, credential rotation, and audit history.
11. Add Event Node to cloud reconciliation for admissions and walk-ins.
12. Add failure/retry tests for synchronization, walk-in capacity, transfer rotation, and finalized-event rules.

## 18. Non-goals

The second-half architecture does not require:

- offline independent scanner validation;
- distributing all ticket secrets to scanner phones;
- TOTP or rotating QR codes;
- PostgreSQL logical replication;
- Kafka or another message broker;
- Kubernetes;
- automatic peer-to-peer scanner synchronization.

The design intentionally keeps one local admission authority so duplicate admission is rejected before a second person enters.
