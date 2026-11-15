<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventManagerAssignment;
use App\Models\EventManagerInvitation;
use App\Models\EventResponsibilityAudit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EventManagerInvitationController extends Controller
{
    public function show(): Response
    {
        $this->requireSecureRequest(request());

        return response()->view('event-manager-invitation')->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->requireSecureRequest($request);

        $token = $request->input('token');
        $invitation = null;

        if ($token !== null) {
            if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
                return $this->notFound();
            }

            $invitation = EventManagerInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->with('event:id,title')
                ->first();

            if ($invitation) {
                $request->session()->put('event_manager_invitation_id', $invitation->id);
            }
        } else {
            $invitationId = $request->session()->get('event_manager_invitation_id');
            if (is_int($invitationId) || (is_string($invitationId) && ctype_digit($invitationId))) {
                $invitation = EventManagerInvitation::query()
                    ->whereKey($invitationId)
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->with('event:id,title')
                    ->first();
            }
        }

        if (! $invitation || ! $invitation->event) {
            return $this->notFound();
        }

        $matchingAccounts = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($invitation->email)])->limit(2)->get();
        if ($matchingAccounts->count() > 1) {
            return $this->notFound();
        }

        $existingAccount = $matchingAccounts->first();
        $currentUser = Auth::guard('web')->user();
        $signInUrl = null;
        if ($existingAccount && ! $currentUser) {
            $request->session()->put('url.intended', route('event-manager-invitation.show', absolute: false));
            $signInUrl = $existingAccount->isStudent()
                ? route('student.auth', absolute: false)
                : route('login', absolute: false);
        }

        return $this->privateJson([
            'invitation' => [
                'email' => $invitation->email,
                'event_title' => $invitation->event->title,
                'requires_account_creation' => ! $existingAccount,
                'can_accept' => $existingAccount
                    ? ! $existingAccount->isAdmin() && $currentUser?->is($existingAccount)
                    : ! $currentUser,
                'sign_in_url' => $signInUrl,
            ],
        ]);
    }

    public function accept(Request $request): JsonResponse
    {
        $this->requireSecureRequest($request);

        $invitationId = $request->session()->get('event_manager_invitation_id');
        if (! (is_int($invitationId) || (is_string($invitationId) && ctype_digit($invitationId)))) {
            return $this->notFound();
        }

        $pendingInvitation = EventManagerInvitation::query()
            ->whereKey($invitationId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        if (! $pendingInvitation) {
            return $this->notFound();
        }

        $matchingAccounts = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($pendingInvitation->email)])->limit(2)->get();
        if ($matchingAccounts->count() > 1) {
            return $this->notFound();
        }

        $emailExists = $matchingAccounts->isNotEmpty();
        $data = [];
        if (! $emailExists) {
            $validation = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:100'],
                'password' => ['required', 'string', 'min:12', 'confirmed', function ($attribute, $value, $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                }],
            ]);
            if ($validation->fails()) {
                return $this->privateJson(['message' => 'Enter a name and a password of at least 12 characters.'], 422);
            }

            $data = $validation->validated();
        } elseif (! Auth::guard('web')->check()) {
            return $this->privateJson(['message' => 'Sign in to the invited account to accept this invitation.'], 409);
        }

        try {
            $eventId = $pendingInvitation->event_id;
            $manager = DB::transaction(function () use ($eventId, $invitationId, $data): ?User {
                $lockedEvent = Event::query()->whereKey($eventId)->lockForUpdate()->first();
                if (! $lockedEvent) {
                    return null;
                }

                $invitation = EventManagerInvitation::query()
                    ->whereKey($invitationId)
                    ->lockForUpdate()
                    ->first();
                if (! $invitation || (int) $invitation->event_id !== (int) $lockedEvent->id
                    || $invitation->accepted_at || $invitation->revoked_at || now()->gte($invitation->expires_at)) {
                    return null;
                }

                $email = mb_strtolower($invitation->email);
                $existingAccounts = User::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->lockForUpdate()
                    ->limit(2)
                    ->get();
                if ($existingAccounts->count() > 1) {
                    return null;
                }

                $existing = $existingAccounts->first();
                $authenticated = Auth::guard('web')->user();

                if ($existing) {
                    if ($existing->isAdmin() || ! $authenticated || $authenticated->isNot($existing)
                        || mb_strtolower($existing->email) !== $email) {
                        return null;
                    }

                    $existing->email_verified_at ??= now();
                    $existing->save();
                    $manager = $existing;
                } else {
                    if ($authenticated) {
                        return null;
                    }

                    $manager = new User;
                    $manager->name = trim($data['name']);
                    $manager->email = $invitation->email;
                    $manager->password = $data['password'];
                    $manager->role = User::ROLE_EVENT_MANAGER;
                    $manager->email_verified_at = now();
                    $manager->save();
                }

                $assignment = EventManagerAssignment::query()
                    ->where('event_id', $lockedEvent->id)
                    ->where('user_id', $manager->id)
                    ->lockForUpdate()
                    ->first();
                if ($assignment) {
                    return null;
                }

                EventManagerAssignment::create([
                    'event_id' => $lockedEvent->id,
                    'user_id' => $manager->id,
                    'assigned_by_user_id' => $invitation->invited_by_user_id,
                ]);

                EventResponsibilityAudit::create([
                    'event_id' => $lockedEvent->id,
                    'event_title' => $lockedEvent->title,
                    'subject_user_id' => $manager->id,
                    'subject_email' => $manager->email,
                    'actor_user_id' => $invitation->invited_by_user_id,
                    'actor_email' => $invitation->invitedBy?->email ?? 'unknown',
                    'action' => EventResponsibilityAudit::ACTION_MANAGER_ASSIGNED,
                    'created_at' => now(),
                ]);

                EventResponsibilityAudit::create([
                    'event_id' => $lockedEvent->id,
                    'event_title' => $lockedEvent->title,
                    'subject_user_id' => $manager->id,
                    'subject_email' => $manager->email,
                    'actor_user_id' => $manager->id,
                    'actor_email' => $manager->email,
                    'action' => EventResponsibilityAudit::ACTION_MANAGER_INVITATION_ACCEPTED,
                    'created_at' => now(),
                ]);

                $invitation->forceFill([
                    'accepted_at' => now(),
                    'accepted_user_id' => $manager->id,
                ])->save();

                EventManagerInvitation::query()
                    ->where('event_id', $lockedEvent->id)
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->where('id', '<>', $invitation->id)
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);

                return $manager;
            });
        } catch (UniqueConstraintViolationException) {
            $manager = null;
        }

        if (! $manager) {
            return $this->notFound();
        }

        $request->session()->forget('event_manager_invitation_id');
        Auth::guard('web')->login($manager);
        $request->session()->regenerate();

        return $this->privateJson([
            'message' => 'Event manager access is ready.',
            'redirect' => route('organizer.dashboard', absolute: false),
        ]);
    }

    private function notFound(): JsonResponse
    {
        return $this->privateJson(['message' => 'This invitation is invalid or expired.'], 404);
    }

    /** @param  array<string, mixed>  $data */
    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function requireSecureRequest(Request $request): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        $url = parse_url((string) config('app.url'));
        abort_unless($request->secure()
            && is_array($url)
            && ($url['scheme'] ?? null) === 'https'
            && ! empty($url['host'])
            && ! isset($url['query'])
            && ! isset($url['fragment']), 503);
    }
}
