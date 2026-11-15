# Keep event management and scanner authority event-scoped

Ingress keeps event ownership, event-manager assignments, and scanner assignments scoped to individual events. The Cloud records manager assignments and their audit history separately from account categories; existing event ownership remains intact, and `event_staff` continues to control scanner event and gate access. This lets one person attend, manage, and scan at different events without turning an event responsibility into a global role.
