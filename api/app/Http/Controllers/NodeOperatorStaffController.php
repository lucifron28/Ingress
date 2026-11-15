<?php

namespace App\Http\Controllers;

use App\Actions\ManageNodeStaffCredential;
use App\Actions\NodeStaffAccess;
use App\Actions\NodeStaffPairing;
use App\Models\Event;
use App\Models\User;
use App\Support\NodeOperatorAuditRecorder;
use App\Support\NodeStaffPairingCode;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

class NodeOperatorStaffController extends Controller
{
    public function index(NodeStaffAccess $staffAccess): View
    {
        $assignments = $this->assignmentRows($staffAccess)
            ->select([
                'events.id as event_id',
                'events.title as event_title',
                'events.starts_at as event_starts_at',
                'event_staff.user_id as staff_id',
                'users.name as staff_name',
                'event_staff.access_point_id as gate_id',
                'access_points.name as gate_name',
            ])
            ->orderBy('events.starts_at')
            ->orderBy('users.name')
            ->get();

        $staffIds = $assignments->pluck('staff_id')->map(fn ($id) => (int) $id)->unique()->values();
        $credentialedStaff = $staffIds->isEmpty() ? [] : DB::table('node_staff_credentials')
            ->whereIn('user_id', $staffIds)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $sessionCounts = $this->activeSessionCounts($staffIds);

        $events = $assignments->groupBy('event_id')->map(function (Collection $rows) use ($credentialedStaff, $sessionCounts): array {
            $event = $rows->first();

            return [
                'id' => (int) $event->event_id,
                'title' => $event->event_title,
                'starts_at' => Carbon::parse($event->event_starts_at)->utc()->format('D, M j, Y H:i').' UTC',
                'staff' => $rows->map(function ($row) use ($credentialedStaff, $sessionCounts): array {
                    $staffId = (int) $row->staff_id;

                    return [
                        'id' => $staffId,
                        'name' => $row->staff_name,
                        'gate' => $row->gate_id === null ? 'All active gates' : ($row->gate_name ?? 'Assigned gate unavailable'),
                        'has_credential' => in_array($staffId, $credentialedStaff, true),
                        'session_count' => $sessionCounts[$staffId] ?? 0,
                    ];
                })->values(),
            ];
        })->values();

        return view('node-operator.staff', compact('events'));
    }

    public function issuePairingQr(
        Event $event,
        User $staff,
        NodeStaffAccess $staffAccess,
        NodeStaffPairing $pairing,
        NodeStaffPairingCode $pairingCode,
        NodeOperatorAuditRecorder $audit,
    ): Response|RedirectResponse {
        $context = $this->assignmentContext($staffAccess, $event, $staff);
        if (! DB::table('node_staff_credentials')->where('user_id', $staff->id)->exists()) {
            return $this->staffList()->withErrors(['staff' => 'Provision a local scanner credential before generating a pairing code.']);
        }

        [$apiUrl, $nodeId] = $this->nodeApiIdentity();
        $operatorId = $this->operatorId();

        try {
            $issued = $pairing->issue($context['cloud_user_id'], (int) $event->id);
        } catch (ValidationException) {
            $audit->recordStaffAction($operatorId, 'staff.pairing.generated', (int) $staff->id, (int) $event->id, 'rejected');

            return $this->staffList()->withErrors(['staff' => 'This assignment or scanner credential changed. Refresh the staff list and try again.']);
        }

        $payload = $pairingCode->payload($apiUrl, $nodeId, $issued['token'], $issued['expires_at']);
        $qrDataUri = $pairingCode->svgDataUri($payload);
        $audit->recordStaffAction($operatorId, 'staff.pairing.generated', (int) $staff->id, (int) $event->id);
        $expiresAt = Carbon::parse($issued['expires_at']);

        return response()->view('node-operator.pairing', [
            'event' => $event,
            'staff' => $staff,
            'gate' => $context['gate'],
            'nodeId' => $nodeId,
            'nodeApiUrl' => $apiUrl,
            'expiresAt' => $expiresAt->toIso8601String(),
            'secondsRemaining' => max(0, $expiresAt->getTimestamp() - now()->getTimestamp()),
            'qrDataUri' => $qrDataUri,
        ]);
    }

    public function cancelPairing(
        Request $request,
        Event $event,
        User $staff,
        NodeStaffAccess $staffAccess,
        NodeStaffPairing $pairing,
        NodeOperatorAuditRecorder $audit,
    ): RedirectResponse {
        $request->validate(['confirmation' => ['required', 'in:CANCEL']]);
        $this->assignmentContext($staffAccess, $event, $staff);
        $operatorId = $this->operatorId();

        try {
            $cancelled = $pairing->cancelForUser((int) $staff->id, (int) $event->id);
        } catch (ValidationException) {
            $audit->recordStaffAction($operatorId, 'staff.pairing.cancelled', (int) $staff->id, (int) $event->id, 'rejected');

            return $this->staffList()->withErrors(['staff' => 'The assignment changed. Refresh the staff list before continuing.']);
        }

        $audit->recordStaffAction(
            $operatorId,
            'staff.pairing.cancelled',
            (int) $staff->id,
            (int) $event->id,
            $cancelled ? 'succeeded' : 'no_pending_code',
        );

        $message = $cancelled
            ? 'The unused pairing code was cancelled. If the scanner already paired, revoke its credential to end those sessions.'
            : 'No unused pairing code remained. If the scanner already paired, revoke its credential to end those sessions.';

        return $this->staffList()->with('staff_message', $message);
    }

    public function provisionCredential(
        Request $request,
        Event $event,
        User $staff,
        NodeStaffAccess $staffAccess,
        ManageNodeStaffCredential $credentials,
        NodeOperatorAuditRecorder $audit,
    ): RedirectResponse {
        $request->validate(['confirmation' => ['required', 'in:PROVISION']]);
        $context = $this->assignmentContext($staffAccess, $event, $staff);
        $operatorId = $this->operatorId();

        try {
            $secret = $credentials->provisionIfMissing($context['cloud_user_id'], (int) $event->id);
        } catch (ValidationException) {
            $audit->recordStaffAction($operatorId, 'staff.credential.provisioned', (int) $staff->id, (int) $event->id, 'rejected');

            return $this->staffList()->withErrors(['staff' => 'This assignment changed. Refresh the staff list before continuing.']);
        }

        if ($secret === null) {
            $audit->recordStaffAction($operatorId, 'staff.credential.provisioned', (int) $staff->id, (int) $event->id, 'rejected');

            return $this->staffList()->withErrors(['staff' => 'A credential already exists. Use the confirmed rotation action if it must be replaced.']);
        }

        $audit->recordStaffAction($operatorId, 'staff.credential.provisioned', (int) $staff->id, (int) $event->id);

        return $this->staffList()->with('credential_issued', [
            'event' => $event->title,
            'staff' => $staff->name,
            'secret' => $secret,
            'rotated' => false,
        ]);
    }

    public function rotateCredential(
        Request $request,
        Event $event,
        User $staff,
        NodeStaffAccess $staffAccess,
        ManageNodeStaffCredential $credentials,
        NodeOperatorAuditRecorder $audit,
    ): RedirectResponse {
        $request->validate(['confirmation' => ['required', 'in:ROTATE']]);
        $context = $this->assignmentContext($staffAccess, $event, $staff);
        $operatorId = $this->operatorId();

        try {
            $secret = $credentials->rotateIfPresent($context['cloud_user_id'], (int) $event->id);
        } catch (ValidationException) {
            $audit->recordStaffAction($operatorId, 'staff.credential.rotated', (int) $staff->id, (int) $event->id, 'rejected');

            return $this->staffList()->withErrors(['staff' => 'This assignment changed. Refresh the staff list before continuing.']);
        }

        if ($secret === null) {
            $audit->recordStaffAction($operatorId, 'staff.credential.rotated', (int) $staff->id, (int) $event->id, 'rejected');

            return $this->staffList()->withErrors(['staff' => 'No credential exists to rotate. Provision one first.']);
        }

        $audit->recordStaffAction($operatorId, 'staff.credential.rotated', (int) $staff->id, (int) $event->id);

        return $this->staffList()->with('credential_issued', [
            'event' => $event->title,
            'staff' => $staff->name,
            'secret' => $secret,
            'rotated' => true,
        ]);
    }

    public function revokeCredential(
        Request $request,
        Event $event,
        User $staff,
        NodeStaffAccess $staffAccess,
        ManageNodeStaffCredential $credentials,
        NodeOperatorAuditRecorder $audit,
    ): RedirectResponse {
        $request->validate(['confirmation' => ['required', 'in:REVOKE']]);
        $context = $this->assignmentContext($staffAccess, $event, $staff);
        $operatorId = $this->operatorId();

        try {
            $credentials->revoke($context['cloud_user_id'], (int) $event->id);
        } catch (ValidationException) {
            $audit->recordStaffAction($operatorId, 'staff.credential.revoked', (int) $staff->id, (int) $event->id, 'rejected');

            return $this->staffList()->withErrors(['staff' => 'This assignment changed. Refresh the staff list before continuing.']);
        }

        $audit->recordStaffAction($operatorId, 'staff.credential.revoked', (int) $staff->id, (int) $event->id);

        return $this->staffList()->with('staff_message', 'The scanner credential, unused pairing codes, and saved scanner sessions were revoked.');
    }

    private function assignmentRows(NodeStaffAccess $staffAccess): Builder
    {
        return $staffAccess->assignments()
            ->join('users', 'users.id', '=', 'event_staff.user_id')
            ->leftJoin('access_points', 'access_points.id', '=', 'event_staff.access_point_id');
    }

    /** @return array{cloud_user_id: int, gate: string} */
    private function assignmentContext(NodeStaffAccess $staffAccess, Event $event, User $staff): array
    {
        abort_unless($event->isPublished(), 404);

        $assignment = $staffAccess->assignments()
            ->leftJoin('access_points', 'access_points.id', '=', 'event_staff.access_point_id')
            ->where('events.id', $event->id)
            ->where('event_staff.user_id', $staff->id)
            ->first(['event_staff.access_point_id', 'access_points.name as gate_name']);
        abort_unless($assignment, 404);

        $cloudUserId = DB::table('node_import_ids')
            ->where('kind', 'user')
            ->where('local_id', $staff->id)
            ->value('cloud_id');
        abort_unless(is_numeric($cloudUserId) && (int) $cloudUserId > 0, 404);

        return [
            'cloud_user_id' => (int) $cloudUserId,
            'gate' => $assignment->access_point_id === null ? 'All active gates' : ($assignment->gate_name ?? 'Assigned gate unavailable'),
        ];
    }

    /** @param Collection<int, int> $staffIds
     * @return array<int, int>
     */
    private function activeSessionCounts(Collection $staffIds): array
    {
        if ($staffIds->isEmpty()) {
            return [];
        }

        $counts = [];
        $expiration = config('sanctum.expiration');
        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $staffIds)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->get(['tokenable_id', 'abilities', 'created_at']);

        foreach ($tokens as $token) {
            if ($token->abilities !== ['node:staff']
                || ($expiration && (! $token->created_at || $token->created_at->lte(now()->subMinutes($expiration))))) {
                continue;
            }

            $staffId = (int) $token->tokenable_id;
            $counts[$staffId] = ($counts[$staffId] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array{0: string, 1: string} */
    private function nodeApiIdentity(): array
    {
        $applicationUrl = config('app.url');
        $parts = is_string($applicationUrl) ? parse_url($applicationUrl) : false;
        $path = is_array($parts) ? rtrim((string) ($parts['path'] ?? ''), '/') : '';
        $pathIsSafe = $path === '' || preg_match('#\A(?:/[A-Za-z0-9_-]+)+\z#D', $path) === 1;
        $nodeId = config('ingress.node_id');

        $lanDemo = config('ingress.mode') === 'node' && config('ingress.lan_demo') === true;
        $expectedScheme = $lanDemo ? 'http' : 'https';
        if (! is_string($applicationUrl) || strlen($applicationUrl) > 480
            || ! is_array($parts) || ($parts['scheme'] ?? null) !== $expectedScheme || empty($parts['host'])
            || ($lanDemo && ($parts['host'] ?? null) !== config('ingress.node_bind_address'))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! $pathIsSafe || ! filter_var($applicationUrl, FILTER_VALIDATE_URL)
            || ! is_string($nodeId) || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $nodeId)) {
            abort(503, 'Set APP_URL to the configured Event Node address before generating pairing codes.');
        }

        return [rtrim($applicationUrl, '/').'/api/v1', $nodeId];
    }

    private function operatorId(): int
    {
        $operatorId = Auth::guard('node_operator')->id();
        abort_unless(is_numeric($operatorId) && (int) $operatorId > 0, 401);

        return (int) $operatorId;
    }

    private function staffList(): RedirectResponse
    {
        return redirect()->to(route('node.operator.staff.index', absolute: false));
    }
}
