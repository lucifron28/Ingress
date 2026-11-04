<?php

namespace App\Console\Commands;

use App\Actions\AcknowledgeAdmissionBatch;
use App\Actions\AcknowledgeWalkInSync;
use App\Actions\BindRuntimeDatabase;
use App\Actions\PrepareAdmissionBatch;
use App\Actions\PrepareWalkInSync;
use App\Actions\SyncWalkInRecovery;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SyncNodeAdmissions extends Command
{
    protected $signature = 'ingress:sync-admissions {event_id? : Cloud event ID, or all activated events}';

    protected $description = 'Send pending node admissions to the cloud with authenticated retries';

    public function handle(BindRuntimeDatabase $runtime, PrepareAdmissionBatch $prepare, AcknowledgeAdmissionBatch $ack,
        PrepareWalkInSync $prepareWalkIn, AcknowledgeWalkInSync $ackWalkIn, SyncWalkInRecovery $recoveries): int
    {
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
        if ($argument !== null && (! is_string($argument) || ! preg_match('/\A[1-9][0-9]*\z/', $argument) || (string) (int) $argument !== $argument)) {
            $this->error('Provide a valid cloud event ID.');

            return self::FAILURE;
        }
        $runtime->execute();
        $events = DB::table('node_event_authorities')->orderBy('cloud_event_id')
            ->when($argument !== null, fn ($query) => $query->where('cloud_event_id', (int) $argument))
            ->pluck('cloud_event_id');
        if ($argument !== null && $events->isEmpty()) {
            $this->error('Event authority is not active on this node.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($events as $eventId) {
            try {
                for ($number = 0; $number < 40; $number++) {
                    $walkIn = $prepareWalkIn->execute((int) $eventId);
                    if ($walkIn === null) {
                        break;
                    }
                    $response = Http::acceptJson()->withHeaders(['X-Ingress-Node' => $nodeId])
                        ->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(15)
                        ->withBody($walkIn['package'], 'application/octet-stream')
                        ->post(rtrim($url, '/').'/node-sync/walk-ins');
                    $body = $response->json();
                    if (! $response->successful() || ! is_array($body) || ($body['issuance_id'] ?? null) !== $walkIn['issuance_id']
                        || ! is_int($body['registration_id'] ?? null) || ! is_int($body['ticket_id'] ?? null)
                        || ! is_string($body['receipt'] ?? null)) {
                        throw ValidationException::withMessages(['sync' => 'Cloud did not confirm the walk-in.']);
                    }
                    $ackWalkIn->execute((int) $eventId, $body['issuance_id'], $body['registration_id'], $body['ticket_id'], $body['receipt']);
                }
            } catch (ConnectionException|ValidationException) {
                $failed = true;
                Log::warning('Walk-in synchronization failed; the pending issuance will be retried.', ['cloud_event_id' => (int) $eventId]);
                $this->error("Walk-in synchronization failed for event {$eventId}; the pending issuance will be retried.");
            }
            try {
                for ($number = 0; $number < 10; $number++) {
                    $recovery = $recoveries->prepare((int) $eventId);
                    if ($recovery === null) {
                        break;
                    }
                    $response = Http::acceptJson()->withHeaders(['X-Ingress-Node' => $nodeId])
                        ->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(15)
                        ->withBody($recovery['package'], 'application/octet-stream')
                        ->post(rtrim($url, '/').'/node-sync/walk-in-recoveries');
                    $body = $response->json();
                    if (! $response->successful() || ! is_array($body) || ($body['recovery_id'] ?? null) !== $recovery['recovery_id']
                        || ! is_string($body['receipt'] ?? null)) {
                        throw ValidationException::withMessages(['sync' => 'Cloud did not confirm the recovery.']);
                    }
                    $recoveries->acknowledge((int) $eventId, $body['recovery_id'], $body['receipt']);
                }
            } catch (ConnectionException|ValidationException) {
                $failed = true;
                Log::warning('Walk-in recovery synchronization failed; the pending record will be retried.', ['cloud_event_id' => (int) $eventId]);
                $this->error("Walk-in recovery synchronization failed for event {$eventId}; the pending record will be retried.");
            }
            try {
                for ($batchNumber = 0; $batchNumber < 10; $batchNumber++) {
                    $batch = $prepare->execute((int) $eventId);
                    if ($batch === null) {
                        break;
                    }
                    $response = Http::acceptJson()->withHeaders(['X-Ingress-Node' => $nodeId])
                        ->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(15)
                        ->withBody($batch['package'], 'application/octet-stream')
                        ->post(rtrim($url, '/').'/node-sync/admissions');
                    $body = $response->json();
                    if (! $response->successful() || ! is_array($body) || ($body['batch_id'] ?? null) !== $batch['batch_id']
                        || ! is_string($body['receipt'] ?? null)) {
                        throw ValidationException::withMessages(['sync' => 'Cloud did not confirm the batch.']);
                    }
                    $ack->execute((int) $eventId, $body['batch_id'], $body['receipt']);
                    $this->info("Synchronized {$batch['count']} admissions for event {$eventId}.");
                }
            } catch (ConnectionException|ValidationException) {
                $failed = true;
                Log::warning('Admission synchronization failed; the pending batch will be retried.', ['cloud_event_id' => (int) $eventId]);
                $this->error("Synchronization failed for event {$eventId}; the pending batch will be retried.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
