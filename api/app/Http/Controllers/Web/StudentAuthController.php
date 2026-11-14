<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventManagerInvitation;
use App\Models\Registration;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Auth\FirebaseIdentityServiceUnavailable;
use App\Services\Auth\FirebaseIdentityVerifier;
use App\Services\Auth\StudentEmailEligibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudentAuthController extends Controller
{
    public function __construct(
        private FirebaseIdentityVerifier $firebaseIdentityVerifier,
        private StudentEmailEligibility $studentEmailEligibility,
    ) {}

    public function show(Request $request): Response
    {
        $this->requireSecureRequest($request);

        $firebaseConfig = [
            'apiKey' => config('services.firebase.api_key'),
            'authDomain' => config('services.firebase.auth_domain'),
            'projectId' => config('services.firebase.project_id'),
            'appId' => config('services.firebase.app_id'),
        ];
        $firebaseConfigured = collect($firebaseConfig)->every(
            fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        );

        return $this->privatePage(response()->view('student.auth', [
            'firebaseConfig' => $firebaseConfig,
            'firebaseConfigured' => $firebaseConfigured,
            'requiresInstitutionalEmail' => $this->studentEmailEligibility->requiresInstitutionalEmail(),
            'institutionalDomain' => $this->studentEmailEligibility->domain(),
        ]));
    }

    public function exchange(Request $request): JsonResponse
    {
        $this->requireSecureRequest($request);

        $validator = Validator::make($request->all(), [
            'id_token' => ['required', 'string', 'max:8192'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('A valid Firebase sign-in token is required.', 422);
        }
        $input = $validator->validated();

        $sessionUser = Auth::guard('web')->user();
        if ($sessionUser && ! $sessionUser->isStudent()) {
            return $this->jsonError('Sign out of the current account before using student sign-in.', 409);
        }

        if (! $this->firebaseIdentityVerifier->isConfigured()) {
            return $this->jsonError('Student sign-in is temporarily unavailable. Please try again later.', 503);
        }

        try {
            $identity = $this->firebaseIdentityVerifier->verifyIdToken($input['id_token']);
        } catch (FirebaseIdentityServiceUnavailable) {
            return $this->jsonError('Student sign-in is temporarily unavailable. Please try again later.', 503);
        }

        if (! $identity || ! $this->studentEmailEligibility->isValidEmail($identity['email'])) {
            return $this->jsonError('Sign-in was not accepted. Use a valid email address verified by Firebase, then try again.', 401);
        }

        if (! $this->studentEmailEligibility->canResolveFirebaseIdentity($identity['uid'], $identity['email'])) {
            return $this->jsonError('New student accounts require an @'.$this->studentEmailEligibility->domain().' email address.', 403);
        }

        if ($sessionUser && (mb_strtolower($sessionUser->email) !== $identity['email']
            || ($sessionUser->firebase_uid !== null && ! hash_equals($sessionUser->firebase_uid, $identity['uid'])))) {
            return $this->jsonError('Sign out of the current account before using student sign-in.', 409);
        }

        try {
            $user = $this->resolveStudent($identity);
        } catch (UniqueConstraintViolationException) {
            $user = null;
        }

        if (! $user) {
            return $this->jsonError('This account cannot use student sign-in. Contact support if you need help.', 403);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $registrationRedirect = $this->pendingRegistrationRedirect($request, $user);
        $redirect = $registrationRedirect ?? route('student.dashboard', absolute: false);
        $invitationId = $request->session()->get('event_manager_invitation_id');
        if ((is_int($invitationId) || (is_string($invitationId) && ctype_digit($invitationId)))
            && EventManagerInvitation::query()
                ->whereKey($invitationId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists()) {
            $redirect = route('event-manager-invitation.show', absolute: false);
        } elseif ($registrationRedirect !== null) {
            $request->session()->forget('public_registration_intent');
        }

        return $this->privateJson([
            'message' => 'Student sign-in successful.',
            'redirect' => $redirect,
        ]);
    }

    public function dashboard(Request $request): Response
    {
        $this->requireSecureRequest($request);

        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User && $this->studentEmailEligibility->canAccess($user), 403);

        $registrations = Registration::query()
            ->with(['event.venue', 'ticketCategory', 'ticket'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return $this->privatePage(response()->view('student.dashboard', compact('user', 'registrations')));
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->requireSecureRequest($request);

        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User && $user->isStudent(), 403);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('student.auth')->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * @param  array{uid: string, email: string, name: ?string}  $identity
     */
    private function resolveStudent(array $identity): ?User
    {
        return DB::transaction(function () use ($identity): ?User {
            $this->studentEmailEligibility->lockPolicyForUpdate();
            if (! $this->studentEmailEligibility->canResolveFirebaseIdentity($identity['uid'], $identity['email'])) {
                return null;
            }

            $user = User::query()
                ->where('firebase_uid', $identity['uid'])
                ->lockForUpdate()
                ->first();
            $emailMatches = User::query()
                ->whereRaw('LOWER(email) = ?', [$identity['email']])
                ->lockForUpdate()
                ->limit(2)
                ->get();

            if ($emailMatches->count() > 1) {
                return null;
            }

            $emailOwner = $emailMatches->first();
            if ($user && $emailOwner && $emailOwner->isNot($user)) {
                return null;
            }

            if (! $user && $emailOwner) {
                if (! $emailOwner->isStudent() || $emailOwner->firebase_uid !== null) {
                    return null;
                }

                $user = $emailOwner;
            }

            if ($user && ! $user->isStudent()) {
                return null;
            }

            if (! $user) {
                $user = new User;
                $user->name = $identity['name'] ?? 'Student';
                $user->email = $identity['email'];
                $user->password = bin2hex(random_bytes(32));
                $user->role = User::ROLE_STUDENT;
            }

            $user->email = $identity['email'];
            $user->firebase_uid = $identity['uid'];
            $user->email_verified_at ??= now();
            $user->save();

            return $user;
        });
    }

    private function pendingRegistrationRedirect(Request $request, User $user): ?string
    {
        $intent = $request->session()->get('public_registration_intent');
        if (! is_array($intent)) {
            return null;
        }

        $eventId = $intent['event_id'] ?? null;
        $categoryId = $intent['category_id'] ?? null;
        if (! (is_int($eventId) || (is_string($eventId) && ctype_digit($eventId)))
            || ! (is_int($categoryId) || (is_string($categoryId) && ctype_digit($categoryId)))) {
            $request->session()->forget('public_registration_intent');

            return null;
        }

        $event = Event::query()->whereKey((int) $eventId)->first();
        $category = TicketCategory::query()
            ->whereKey((int) $categoryId)
            ->where('event_id', (int) $eventId)
            ->where('is_active', true)
            ->first();

        if (! $event || ! $category || ! $event->isRegistrationOpen()
            || ! $this->studentEmailEligibility->canRegister($user)
            || ($event->registration_eligibility !== Event::ELIGIBILITY_STUDENT
                && $category->eligibility !== TicketCategory::ELIGIBILITY_STUDENT)) {
            $request->session()->forget('public_registration_intent');

            return null;
        }

        return route('public.events.register', [
            'event' => $event->slug,
            'category' => $category->id,
        ], absolute: false);
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

    private function privatePage(Response $response): Response
    {
        $localViteSources = app()->environment('local')
            ? ' http://localhost:5173 http://127.0.0.1:5173'
            : '';
        $localViteSockets = app()->environment('local')
            ? ' ws://localhost:5173 ws://127.0.0.1:5173'
            : '';

        return $response->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'{$localViteSources}; style-src 'unsafe-inline'; connect-src 'self' https://identitytoolkit.googleapis.com https://securetoken.googleapis.com https://firebaseinstallations.googleapis.com{$localViteSockets}; img-src 'self' data:; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return $this->privateJson(['message' => $message], $status);
    }
}
