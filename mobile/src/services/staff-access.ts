import type { UserProfile } from '../context/AuthContext';

export function canUseStaffEntry(user: UserProfile | null): boolean {
  return user === null || user.is_event_staff !== false || ['staff', 'organizer', 'admin'].includes(user.role);
}
