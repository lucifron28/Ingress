<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /**
     * Determine whether the user can view any events.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the event.
     */
    public function view(User $user, Event $event): bool
    {
        if ($event->isPublished()) {
            return true;
        }

        return $user->managesEvent($event);
    }

    /**
     * Determine whether the user can create events.
     */
    public function create(User $user): bool
    {
        return $user->isOrganizer();
    }

    /**
     * Determine whether the user can update the event.
     */
    public function update(User $user, Event $event): bool
    {
        return $user->managesEvent($event);
    }

    /**
     * Determine whether the user can delete the event.
     */
    public function delete(User $user, Event $event): bool
    {
        return (int) $event->organizer_id === (int) $user->id || $user->isAdmin();
    }

    /**
     * Determine whether the user can publish the event.
     */
    public function publish(User $user, Event $event): bool
    {
        return $user->managesEvent($event);
    }

    /**
     * Determine whether the user can cancel the event.
     */
    public function cancel(User $user, Event $event): bool
    {
        return $user->managesEvent($event);
    }

    /**
     * Only platform administrators can approve event-manager assignments.
     */
    public function manageManagers(User $user, Event $event): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can manage staff or access points.
     */
    public function manageStaff(User $user, Event $event): bool
    {
        return $user->managesEvent($event);
    }
}
