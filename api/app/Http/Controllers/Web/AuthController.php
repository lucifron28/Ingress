<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\EventManagerInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Show organizer login form.
     */
    public function showLoginForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('organizer.dashboard');
        }

        return view('auth.login');
    }

    /**
     * Handle web login submission.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = Auth::user();
            if (! $user->hasEventManagementAccess() && ! $this->canAcceptPendingInvitation($request, $user)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors([
                    'email' => 'Access restricted to accounts with event-management access.',
                ]);
            }

            return redirect()->intended(route('organizer.dashboard'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Handle web logout.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function canAcceptPendingInvitation(Request $request, User $user): bool
    {
        $invitationId = $request->session()->get('event_manager_invitation_id');
        if (! (is_int($invitationId) || (is_string($invitationId) && ctype_digit($invitationId)))) {
            return false;
        }

        return EventManagerInvitation::query()
            ->whereKey($invitationId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
