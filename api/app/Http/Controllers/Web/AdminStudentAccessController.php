<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\StudentEmailEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminStudentAccessController extends Controller
{
    public function index(StudentEmailEligibility $eligibility): View
    {
        $this->authorizeAdministrator();

        $audits = DB::table('student_access_policy_audits')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return view('admin.student-access', [
            'requiresInstitutionalEmail' => $eligibility->requiresInstitutionalEmail(),
            'institutionalDomain' => $eligibility->domain(),
            'audits' => $audits,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $admin = $this->authorizeAdministrator();

        $validated = $request->validate([
            'require_institutional_email' => ['required', 'boolean'],
        ]);
        $newRequired = (bool) $validated['require_institutional_email'];
        $changed = DB::transaction(function () use ($admin, $newRequired): bool {
            $setting = DB::table('student_access_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless($setting, 503);

            $oldRequired = (bool) $setting->require_institutional_email;
            if ($oldRequired === $newRequired) {
                return false;
            }

            $changedAt = now();
            DB::table('student_access_settings')->where('id', 1)->update([
                'require_institutional_email' => $newRequired,
                'policy_enabled_at' => $newRequired ? $changedAt : null,
                'updated_at' => $changedAt,
            ]);
            DB::table('student_access_policy_audits')->insert([
                'admin_user_id' => $admin->id,
                'admin_email' => $admin->email,
                'old_required' => $oldRequired,
                'new_required' => $newRequired,
                'created_at' => $changedAt,
            ]);

            return true;
        });

        $message = $changed
            ? 'Student email policy updated. Existing accounts and issued tickets remain in place.'
            : 'Student email policy was already set that way. No change was recorded.';

        return redirect()->route('admin.settings.student-access')->with('success', $message);
    }

    private function authorizeAdministrator(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $user;
    }
}
