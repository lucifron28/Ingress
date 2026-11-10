<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Mail\EventManagerInvitationMail;
use App\Models\Event;
use App\Models\EventManagerAssignment;
use App\Models\EventManagerInvitation;
use App\Models\EventResponsibilityAudit;
use App\Models\User;
use App\Support\SensitiveMailTransport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EventManagerController extends Controller
{
    public function store(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize('manageManagers', $event);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);
        $email = mb_strtolower(trim($validated['email']));
        $matchingAccounts = User::query()->whereRaw('LOWER(email) = ?', [$email])->limit(2)->get();
        if ($matchingAccounts->count() > 1) {
            return back()->withErrors(['email' => 'This email matches more than one account. Contact the platform administrator.']);
        }

        $manager = $matchingAccounts->first();

        if (! $manager) {
            return $this->invite($request, $event, $email);
        }

        if ($manager->isAdmin()) {
            return back()->withErrors(['email' => 'Platform administrators do not need an event-manager assignment.']);
        }

        try {
            $created = DB::transaction(function () use ($event, $manager, $request): bool {
                $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
                $lockedManager = User::query()->whereKey($manager->getKey())->lockForUpdate()->firstOrFail();

                EventManagerInvitation::query()
                    ->where('event_id', $lockedEvent->id)
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower($lockedManager->email)])
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);

                $assignment = EventManagerAssignment::query()
                    ->where('event_id', $lockedEvent->id)
                    ->where('user_id', $lockedManager->id)
                    ->lockForUpdate()
                    ->first();

                if ($assignment) {
                    return false;
                }

                EventManagerAssignment::create([
                    'event_id' => $lockedEvent->id,
                    'user_id' => $lockedManager->id,
                    'assigned_by_user_id' => $request->user()->id,
                ]);

                EventResponsibilityAudit::create([
                    'event_id' => $lockedEvent->id,
                    'event_title' => $lockedEvent->title,
                    'subject_user_id' => $lockedManager->id,
                    'subject_email' => $lockedManager->email,
                    'actor_user_id' => $request->user()->id,
                    'actor_email' => $request->user()->email,
                    'action' => EventResponsibilityAudit::ACTION_MANAGER_ASSIGNED,
                    'created_at' => now(),
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $created = false;
        }

        return redirect()->route('organizer.events.show', $event)->with(
            $created ? 'success' : 'info',
            $created ? 'Event manager assigned successfully.' : 'That account already manages this event.',
        );
    }

    public function revoke(Request $request, Event $event, User $manager): RedirectResponse
    {
        Gate::authorize('manageManagers', $event);

        DB::transaction(function () use ($event, $manager, $request): void {
            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $lockedManager = User::query()->whereKey($manager->getKey())->lockForUpdate()->firstOrFail();
            $assignment = EventManagerAssignment::query()
                ->where('event_id', $lockedEvent->id)
                ->where('user_id', $lockedManager->id)
                ->lockForUpdate()
                ->firstOrFail();

            $assignment->delete();
            EventManagerInvitation::query()
                ->where('event_id', $lockedEvent->id)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($lockedManager->email)])
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            EventResponsibilityAudit::create([
                'event_id' => $lockedEvent->id,
                'event_title' => $lockedEvent->title,
                'subject_user_id' => $lockedManager->id,
                'subject_email' => $lockedManager->email,
                'actor_user_id' => $request->user()->id,
                'actor_email' => $request->user()->email,
                'action' => EventResponsibilityAudit::ACTION_MANAGER_REVOKED,
                'created_at' => now(),
            ]);
        });

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'Event-manager access has been revoked.');
    }

    private function invite(Request $request, Event $event, string $email): RedirectResponse
    {
        if (config('ingress.mode') !== 'cloud') {
            return back()->withErrors(['email' => 'Create the account on the Cloud runtime before assigning it.']);
        }

        if (! SensitiveMailTransport::canSendWithoutLogging()) {
            return back()->withErrors(['email' => 'Invitation delivery is unavailable. Contact the platform administrator.']);
        }

        $applicationUrl = rtrim((string) config('app.url'), '/');
        $parsedUrl = parse_url($applicationUrl);
        if (! is_array($parsedUrl) || empty($parsedUrl['host'])
            || (! app()->environment(['local', 'testing'])
                && (! $request->secure() || ($parsedUrl['scheme'] ?? null) !== 'https'))) {
            return back()->withErrors(['email' => 'Invitation delivery is unavailable. Contact the platform administrator.']);
        }

        $token = bin2hex(random_bytes(32));
        try {
            $invitation = DB::transaction(function () use ($event, $email, $token, $request): EventManagerInvitation {
                $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

                EventManagerInvitation::query()
                    ->where('event_id', $lockedEvent->id)
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);

                return EventManagerInvitation::create([
                    'event_id' => $lockedEvent->id,
                    'email' => $email,
                    'token_hash' => hash('sha256', $token),
                    'invited_by_user_id' => $request->user()->id,
                    'expires_at' => now()->addHours(48),
                ]);
            });

            $invitationUrl = $applicationUrl.'/event-manager-invitation#'.$token;
            Mail::to($email)->send(new EventManagerInvitationMail(
                $event->title,
                $invitationUrl,
                $email,
            ));

            EventResponsibilityAudit::create([
                'event_id' => $event->id,
                'event_title' => $event->title,
                'subject_email' => $email,
                'actor_user_id' => $request->user()->id,
                'actor_email' => $request->user()->email,
                'action' => EventResponsibilityAudit::ACTION_MANAGER_INVITED,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['email' => 'Invitation delivery could not be completed. Please try again.']);
        } catch (Throwable) {
            if (isset($invitation)) {
                $invitation->forceFill(['revoked_at' => now()])->save();
            }

            return back()->withErrors(['email' => 'Invitation delivery could not be completed. Please try again.']);
        }

        return redirect()->route('organizer.events.show', $event)
            ->with('success', 'An event-manager invitation was sent.');
    }
}
