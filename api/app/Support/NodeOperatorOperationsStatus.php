<?php

namespace App\Support;

use App\Actions\AuthorityGrant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class NodeOperatorOperationsStatus
{
    public function __construct(private AuthorityGrant $grants, private NodeSyncRunStatus $syncRuns) {}

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $authorities = DB::table('node_event_authorities as a')
            ->join('events as e', 'e.id', '=', 'a.event_id')
            ->orderBy('e.id')
            ->get([
                'a.cloud_event_id', 'a.event_id', 'a.snapshot_id', 'a.version', 'a.sha256', 'a.grant',
                'e.title', 'e.snapshot_version',
            ]);
        $syncConfigured = $this->syncConfigurationIsSafe();
        $authorityUncertain = false;
        $events = [];

        foreach ($authorities as $authority) {
            $verified = $this->authorityIsCurrentAndValid($authority);
            $counts = $verified ? $this->recordCounts((int) $authority->event_id) : null;
            $authorityUncertain = $authorityUncertain || ! $verified;
            $events[] = [
                'localId' => (int) $authority->event_id,
                'cloudId' => (int) $authority->cloud_event_id,
                'title' => $authority->title,
                'snapshotVersion' => (int) $authority->version,
                'authorityValid' => $verified,
                'local' => $counts['local'] ?? null,
                'pending' => $counts['pending'] ?? null,
                'lastAcknowledgedAt' => $verified ? $this->lastAcknowledgedAt((int) $authority->event_id) : null,
                'lastSyncRun' => $verified ? $this->syncRuns->forEvent((int) $authority->event_id) : null,
            ];
        }

        $appParts = $this->httpsAppUrlParts();
        $httpsConfigured = $appParts !== null;
        $nodeId = config('ingress.node_id');

        return [
            'nodeId' => is_string($nodeId) ? $nodeId : 'Not configured',
            'host' => $appParts['host'] ?? 'HTTPS URL not configured',
            'httpsConfigured' => $httpsConfigured,
            'runtimeProfile' => PHP_OS_FAMILY.' · '.$this->phpProfile(),
            'databaseDriver' => DB::connection()->getDriverName(),
            'syncConfigured' => $syncConfigured,
            'authorityUncertain' => $authorityUncertain,
            'hasActiveAuthority' => $events !== [],
            'events' => $events,
            'canSync' => $syncConfigured && $events !== [] && ! $authorityUncertain,
        ];
    }

    /** @return array{cloudEventId: int, pending: array{admissions: int, walkIns: int, recoveries: int, total: int}}|null */
    public function syncTarget(int $localEventId): ?array
    {
        if ($localEventId < 1 || ! $this->syncConfigurationIsSafe()) {
            return null;
        }

        $authorities = DB::table('node_event_authorities as a')
            ->join('events as e', 'e.id', '=', 'a.event_id')
            ->orderBy('a.event_id')
            ->get([
                'a.cloud_event_id', 'a.event_id', 'a.snapshot_id', 'a.version', 'a.sha256', 'a.grant',
                'e.snapshot_version',
            ]);
        if ($authorities->isEmpty()) {
            return null;
        }

        $target = null;
        foreach ($authorities as $authority) {
            if (! $this->authorityIsCurrentAndValid($authority)) {
                return null;
            }
            if ((int) $authority->event_id === $localEventId) {
                $target = (int) $authority->cloud_event_id;
            }
        }

        if ($target === null) {
            return null;
        }

        return [
            'cloudEventId' => $target,
            'pending' => $this->recordCounts($localEventId)['pending'],
        ];
    }

    private function syncConfigurationIsSafe(): bool
    {
        $url = config('ingress.cloud_sync_url');
        $parts = is_string($url) ? parse_url($url) : false;
        $rawKey = config('ingress.node_sync_key');
        $decodedKey = is_string($rawKey) ? base64_decode($rawKey, true) : false;

        return config('ingress.mode') === 'node'
            && $this->httpsAppUrlParts() !== null
            && is_string(config('ingress.node_id'))
            && preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', config('ingress.node_id')) === 1
            && is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && rtrim($parts['path'] ?? '', '/') === '/api/v1'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_string($decodedKey)
            && strlen($decodedKey) === 32
            && hash_equals(base64_encode($decodedKey), $rawKey);
    }

    /** @return array<string, mixed>|null */
    private function httpsAppUrlParts(): ?array
    {
        $url = config('app.url');
        $parts = is_string($url) ? parse_url($url) : false;

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            ? $parts
            : null;
    }

    private function phpProfile(): string
    {
        return match (PHP_SAPI) {
            'fpm-fcgi' => 'PHP-FPM',
            'cgi-fcgi' => 'PHP FastCGI',
            'apache2handler' => 'Apache PHP',
            default => 'PHP '.PHP_SAPI,
        };
    }

    private function authorityIsCurrentAndValid(object $authority): bool
    {
        $nodeId = config('ingress.node_id');
        if (! is_string($nodeId) || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $nodeId)) {
            return false;
        }

        $import = DB::table('node_event_imports')->where('cloud_event_id', $authority->cloud_event_id)
            ->where('event_id', $authority->event_id)->first();
        $head = DB::table('received_event_heads')->where('event_id', $authority->cloud_event_id)->first();
        $snapshot = DB::table('received_snapshots')->where('id', $authority->snapshot_id)->first();
        $eventLink = DB::table('node_import_ids')->where('kind', 'event')
            ->where('cloud_id', $authority->cloud_event_id)->first();

        if (! $import || ! $head || ! $snapshot || ! $eventLink
            || (int) $eventLink->local_id !== (int) $authority->event_id
            || (int) $import->version !== (int) $authority->version
            || (int) $head->version !== (int) $import->version
            || (int) $snapshot->event_id !== (int) $authority->cloud_event_id
            || (int) $snapshot->version !== (int) $authority->version
            || (int) ($authority->snapshot_version ?? 0) !== (int) $authority->version
            || ! is_string($authority->snapshot_id) || ! hash_equals($authority->snapshot_id, $import->snapshot_id)
            || ! is_string($authority->sha256) || ! hash_equals($authority->sha256, $import->sha256)
            || ! hash_equals($authority->sha256, $snapshot->sha256)) {
            return false;
        }

        try {
            $grant = $this->grants->verify($authority->grant);
        } catch (Throwable) {
            return false;
        }

        return $grant === [
            'protocol' => 1,
            'node_id' => $nodeId,
            'snapshot_id' => $authority->snapshot_id,
            'event_id' => (int) $authority->cloud_event_id,
            'version' => (int) $authority->version,
            'sha256' => $authority->sha256,
        ];
    }

    /** @return array{local: array{admissions: int, walkIns: int, recoveries: int}, pending: array{admissions: int, walkIns: int, recoveries: int, total: int}} */
    private function recordCounts(int $eventId): array
    {
        $localAdmissions = DB::table('admissions as a')
            ->join('tickets as t', 't.id', '=', 'a.ticket_id')
            ->join('registrations as r', 'r.id', '=', 't.registration_id')
            ->join('access_points as g', 'g.id', '=', 'a.access_point_id')
            ->where('r.event_id', $eventId)
            ->count();
        $pendingAdmissions = DB::table('admissions as a')
            ->join('tickets as t', 't.id', '=', 'a.ticket_id')
            ->join('registrations as r', 'r.id', '=', 't.registration_id')
            ->join('access_points as g', 'g.id', '=', 'a.access_point_id')
            ->where('r.event_id', $eventId)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('node_synced_admissions as s')
                ->whereColumn('s.admission_id', 'a.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('node_walk_in_issuances as w')
                ->whereColumn('w.ticket_id', 'a.ticket_id')->whereNull('w.synced_at'))
            ->count();
        $localWalkIns = DB::table('node_walk_in_issuances')->where('event_id', $eventId)->count();
        $pendingWalkIns = DB::table('node_walk_in_issuances')->where('event_id', $eventId)->whereNull('synced_at')->count();
        $localRecoveries = DB::table('node_walk_in_recoveries as r')
            ->join('node_walk_in_issuances as w', 'w.id', '=', 'r.issuance_id')
            ->where('w.event_id', $eventId)->count();
        $pendingRecoveries = DB::table('node_walk_in_recoveries as r')
            ->join('node_walk_in_issuances as w', 'w.id', '=', 'r.issuance_id')
            ->where('w.event_id', $eventId)->whereNotNull('w.synced_at')->whereNull('r.synced_at')->count();

        return [
            'local' => [
                'admissions' => $localAdmissions,
                'walkIns' => $localWalkIns,
                'recoveries' => $localRecoveries,
            ],
            'pending' => [
                'admissions' => $pendingAdmissions,
                'walkIns' => $pendingWalkIns,
                'recoveries' => $pendingRecoveries,
                'total' => $pendingAdmissions + $pendingWalkIns + $pendingRecoveries,
            ],
        ];
    }

    private function lastAcknowledgedAt(int $eventId): ?CarbonImmutable
    {
        $timestamps = [
            DB::table('node_synced_admissions as s')
                ->join('admissions as a', 'a.id', '=', 's.admission_id')
                ->join('tickets as t', 't.id', '=', 'a.ticket_id')
                ->join('registrations as r', 'r.id', '=', 't.registration_id')
                ->where('r.event_id', $eventId)->max('s.synced_at'),
            DB::table('node_walk_in_issuances')->where('event_id', $eventId)->max('synced_at'),
            DB::table('node_walk_in_recoveries as r')
                ->join('node_walk_in_issuances as w', 'w.id', '=', 'r.issuance_id')
                ->where('w.event_id', $eventId)->max('r.synced_at'),
        ];
        $latest = null;
        foreach ($timestamps as $timestamp) {
            if (! is_string($timestamp) || $timestamp === '') {
                continue;
            }
            $candidate = CarbonImmutable::parse($timestamp)->utc();
            if ($latest === null || $candidate->greaterThan($latest)) {
                $latest = $candidate;
            }
        }

        return $latest;
    }
}
