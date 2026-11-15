# Ingress domain

Ingress separates a person's identity from the responsibilities they hold for individual events. This vocabulary applies across the Cloud, Scanner, and Event Node.

## People and responsibilities

**Platform administrator**:
A person with global Ingress governance authority, including approving event managers.
_Avoid_: Event manager, organizer

**Event owner**:
The person responsible for a particular event. Ownership applies to that event and does not grant access to other events.
_Avoid_: Platform administrator

**Event manager**:
A person approved to manage one or more specifically assigned events. Their authority ends when each assignment is revoked.
_Avoid_: Global organizer

**Scanner staff**:
A person authorized to scan tickets for an assigned event and, when specified, an assigned gate.
_Avoid_: Event manager

**Attendee**:
A person registered for or holding a ticket to an event. Attendee status does not grant event-management authority.
_Avoid_: User role

**Event Node operator**:
A person who manages a local Event Node. This responsibility is separate from Cloud event management and scanner staff.
_Avoid_: Platform administrator
