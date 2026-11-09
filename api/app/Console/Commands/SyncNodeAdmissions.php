<?php

namespace App\Console\Commands;

use App\Actions\AcknowledgeAdmissionBatch;
use App\Actions\AcknowledgeWalkInSync;
use App\Actions\BindRuntimeDatabase;
use App\Actions\PrepareAdmissionBatch;
use App\Actions\PrepareWalkInSync;
use App\Actions\SyncWalkInRecovery;
use App\Support\NodeSyncRunStatus;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SyncNodeAdmissions extends Command
{
    protected $signature = 'ingress:sync-admissions {event_id? : Cloud event ID, or all activated events} {--single-pass : Send at most one bounded item of each type for one event}';

    protected $description = 'Send pending node admissions to the cloud with authenticated retries';

    public function handle(
        BindRuntimeDatabase $runtime,
        PrepareAdmissionBatch $prepare,
        AcknowledgeAdmissionBatch $ack,
        PrepareWalkInSync $prepareWalkIn,
        AcknowledgeWalkInSync $ackWalkIn,
        SyncWalkInRecovery $recoveries,
        NodeSyncRunStatus $syncRuns,
    ): int {
        $url = config('ingress.cloud_sync_url');
        $nodeId = config('ingress.node_id');
        $parts = is_string($url) ? parse_url($url) : false;
        if (config('ingress.mode') !== 'node' || ! is_string($nodeId) || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || rtrim($parts['path'] ?? '', '/') !== '/api/v1' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('Configure an HTTPS cloud API URL and a valid node identity.');

            return self::FAILURE;
        }

        $argument = $this->argument('event_id');
        $singlePass = (bool) $this->option('single-pass');
        if ($argument !== null && (! is_string($argument) || ! preg_match('/\A[1-9][0-9]*\z/', $argument)
                || (string) (int) $argument !== $argument)) {
            $this->error('Provide a valid cloud event ID.');

            return self::FAILURE;
        }
        if ($singlePass && $argument === null) {
            $this->error('A single sync pass requires one event ID.');

            return self::FAILURE;
        }

        $runtime->execute();
        // The scheduler and the operator action use this same database-backed lock.
        $lock = Cache::store('database')->lock('ingress:sync-admissions', 600);
        if (! $lock->get()) {
            $this->warn('Admission synchronization is already running.');

            return 75;
        }

        try {
            return $this->synchronize(
                $url,
                $nodeId,
                $argument,
                $singlePass,
                $prepare,
                $ack,
                $prepareWalkIn,
                $ackWalkIn,
                $recoveries,
                $syncRuns,
            );
        } finally {
            $lock->release();
        }
    }

    private function synchronize(
        string $url,
        string $nodeId,
        ?string $argument,
        bool $singlePass,
        PrepareAdmissionBatch $prepare,
        AcknowledgeAdmissionBatch $ack,
        PrepareWalkInSync $prepareWalkIn,
        AcknowledgeWalkInSync $ackWalkIn,
        SyncWalkInRecovery $recoveries,
        NodeSyncRunStatus $syncRuns,
    ): int {
        $events = DB::table('node_event_authorities')->orderBy('cloud_event_id')
            ->when($argument !== null, fn ($query) => $query->where('cloud_event_id', (int) $argument))
            ->get(['cloud_event_id', 'event_id']);
        if ($argument !== null && $events->isEmpty()) {
            $this->error('Event authority is not active on this node.');

            return self::FAILURE;
        }

        $failed = false;
        // The regular scheduler pass leaves slack under its ten-minute shared lock.
        $deadline = hrtime(true) + ($singlePass ? 25_000_000_000 : 480_000_000_000);
        $hasTime = static fn (): bool => hrtime(true) < $deadline;
        $connectTimeout = $singlePass ? 2 : 5;
        $requestTimeout = $singlePass ? 4 : 15;
        $walkInPasses = $singlePass ? 1 : 40;
        $recoveryPasses = $singlePass ? 1 : 10;
        $admissionPasses = $singlePass ? 1 : 10;

        foreach ($events as $eventId) {
            if (! $hasTime()) {
                $failed = true;
                break;
            }
            $cloudEventId = (int) $eventId->cloud_event_id;
            $localEventId = (int) $eventId->event_id;
            $eventFailed = false;
            try {
                for ($number = 0; $number < $walkInPasses && $hasTime(); $number++) {
                    $walkIn = $prepareWalkIn->execute($cloudEventId);
                    if ($walkIn === null) {
                        break;
                    }
                    if (! $hasTime()) {
                        $failed = true;
                        break;
                    }
                    $response = Http::acceptJson()->withHeaders(['X-Ingress-Node' => $nodeId])
                        ->withOptions(['allow_redirects' => false])->connectTimeout($connectTimeout)->timeout($requestTimeout)
                        ->withBody($walkIn['package'], 'application/octet-stream')
                        ->post(rtrim($url, '/').'/node-sync/walk-ins');
                    $body = $response->json();
                    if (! $response->successful() || ! is_array($body) || ($body['issuance_id'] ?? null) !== $walkIn['issuance_id']
                        || ! is_int($body['registration_id'] ?? null) || ! is_int($body['ticket_id'] ?? null)
                        || ! is_string($body['receipt'] ?? null)) {
                        throw ValidationException::withMessages(['sync' => 'Cloud did not confirm the walk-in.']);
                    }
                    $ackWalkIn->execute($cloudEventId, $body['issuance_id'], $body['registration_id'], $body['ticket_id'], $body['receipt']);
                }
            } catch (ConnectionException|ValidationException) {
                $failed = $eventFailed = true;
                Log::warning('Walk-in synchronization failed; the pending issuance will be retried.', ['cloud_event_id' => $cloudEventId]);
                $this->error("Walk-in synchronization failed for event {$cloudEventId}; the pending issuance will be retried.");
            }
            try {
                for ($number = 0; $number < $recoveryPasses && $hasTime(); $number++) {
                    $recovery = $recoveries->prepare($cloudEventId);
                    if ($recovery === null) {
                        break;
                    }
                    if (! $hasTime()) {
                        $failed = true;
                        break;
                    }
                    $response = Http::acceptJson()->withHeaders(['X-Ingress-Node' => $nodeId])
                        ->withOptions(['allow_redirects' => false])->connectTimeout($connectTimeout)->timeout($requestTimeout)
                        ->withBody($recovery['package'], 'application/octet-stream')
                        ->post(rtrim($url, '/').'/node-sync/walk-in-recoveries');
                    $body = $response->json();
                    if (! $response->successful() || ! is_array($body) || ($body['recovery_id'] ?? null) !== $recovery['recovery_id']
                        || ! is_string($body['receipt'] ?? null)) {
                        throw ValidationException::withMessages(['sync' => 'Cloud did not confirm the recovery.']);
                    }
                    $recoveries->acknowledge($cloudEventId, $body['recovery_id'], $body['receipt']);
                }
            } catch (ConnectionException|ValidationException) {
                $failed = $eventFailed = true;
                Log::warning('Walk-in recovery synchronization failed; the pending record will be retried.', ['cloud_event_id' => $cloudEventId]);
                $this->error("Walk-in recovery synchronization failed for event {$cloudEventId}; the pending record will be retried.");
            }
            try {
                for ($batchNumber = 0; $batchNumber < $admissionPasses && $hasTime(); $batchNumber++) {
                    $batch = $prepare->execute($cloudEventId);
                    if ($batch === null) {
                        break;
                    }
                    if (! $hasTime()) {
                        $failed = true;
                        break;
                    }
                    $response = Http::acceptJson()->withHeaders(['X-Ingress-Node' => $nodeId])
                        ->withOptions(['allow_redirects' => false])->connectTimeout($connectTimeout)->timeout($requestTimeout)
                        ->withBody($batch['package'], 'application/octet-stream')
                        ->post(rtrim($url, '/').'/node-sync/admissions');
                    $body = $response->json();
                    if (! $response->successful() || ! is_array($body) || ($body['batch_id'] ?? null) !== $batch['batch_id']
                        || ! is_string($body['receipt'] ?? null)) {
                        throw ValidationException::withMessages(['sync' => 'Cloud did not confirm the batch.']);
                    }
                    $ack->execute($cloudEventId, $body['batch_id'], $body['receipt']);
                    $this->info("Synchronized {$batch['count']} admissions for event {$cloudEventId}.");
                }
            } catch (ConnectionException|ValidationException) {
                $failed = $eventFailed = true;
                Log::warning('Admission synchronization failed; the pending batch will be retried.', ['cloud_event_id' => $cloudEventId]);
                $this->error("Synchronization failed for event {$cloudEventId}; the pending batch will be retried.");
            }
            if (! $hasTime()) {
                $failed = $eventFailed = true;
            }
            $syncRuns->record($localEventId, $eventFailed ? 'failed' : 'succeeded');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
