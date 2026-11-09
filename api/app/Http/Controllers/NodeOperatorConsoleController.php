<?php

namespace App\Http\Controllers;

use App\Support\NodeOperatorAuditRecorder;
use App\Support\NodeOperatorOperationsStatus;
use App\Support\NodeSyncRunStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

class NodeOperatorConsoleController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        $guard = Auth::guard('node_operator');
        $operator = $guard->user();
        if ($operator?->is_active) {
            return redirect()->to(route('node.operator.dashboard', absolute: false));
        }

        if ($operator) {
            $guard->logout();
        }

        return view('node-operator.login');
    }

    public function login(Request $request, NodeOperatorAuditRecorder $audit): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return redirect()->to(route('node.operator.login', absolute: false))
                ->withErrors($validator)
                ->withInput($request->only('email'));
        }

        $credentials = $validator->validated();
        $credentials['email'] = mb_strtolower(trim($credentials['email']));
        $credentials['is_active'] = true;

        $guard = Auth::guard('node_operator');
        if (! $guard->attempt($credentials)) {
            return redirect()->to(route('node.operator.login', absolute: false))->withErrors([
                'email' => 'These credentials do not match our records.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();
        $operator = $guard->user();
        $audit->recordLoginSucceeded((int) $operator->getAuthIdentifier());

        return redirect()->to(route('node.operator.dashboard', absolute: false));
    }

    public function dashboard(NodeOperatorOperationsStatus $operations): View
    {
        return view('node-operator.dashboard', [
            'operator' => Auth::guard('node_operator')->user(),
            'operations' => $operations->dashboard(),
        ]);
    }

    public function syncEvent(
        int $event,
        NodeOperatorOperationsStatus $operations,
        NodeSyncRunStatus $syncRuns,
        NodeOperatorAuditRecorder $audit,
    ): RedirectResponse {
        $authority = DB::table('node_event_authorities')->where('event_id', $event)->first();
        abort_unless($authority, 404);

        $syncTarget = $operations->syncTarget($event);
        abort_unless($syncTarget !== null, 409, 'Synchronization is not currently safe. Review the event status first.');
        abort_if($syncTarget['pending']['total'] === 0, 409, 'There are no pending records to synchronize.');

        $operatorId = (int) Auth::guard('node_operator')->id();
        try {
            $exitCode = Artisan::call('ingress:sync-admissions', [
                'event_id' => (string) $syncTarget['cloudEventId'],
                '--single-pass' => true,
            ]);
        } catch (Throwable) {
            $exitCode = 1;
        }

        $outcome = match ($exitCode) {
            0 => 'succeeded',
            75 => 'busy',
            default => 'failed',
        };
        $syncRuns->record($event, $outcome);
        $audit->recordSyncRun($operatorId, $event, $outcome);

        [$kind, $message] = match ($outcome) {
            'succeeded' => ['success', 'One bounded sync pass finished. Refresh the pending counts to see which records received cloud acknowledgments. Any remaining records stay pending for later retries.'],
            'busy' => ['warning', 'A synchronization pass is already running. No second pass was started. Refresh the page in a moment.'],
            default => ['warning', 'The sync pass could not complete. Pending records remain on this node. Admissions can continue on the venue LAN; restore cloud connectivity and retry when ready.'],
        };

        return redirect()->to(route('node.operator.dashboard', absolute: false))
            ->with('syncStatus', ['kind' => $kind, 'message' => $message]);
    }

    public function logout(Request $request, NodeOperatorAuditRecorder $audit): RedirectResponse
    {
        $operatorId = Auth::guard('node_operator')->id();
        $audit->recordLogoutSucceeded((int) $operatorId);

        Auth::guard('node_operator')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(route('node.operator.login', absolute: false));
    }
}
