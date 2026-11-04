<?php

/**
 * Worker used by verify_load_recovery.php for concurrent gate and sync runs.
 */

use App\Actions\AdmitTicket;
use App\Actions\PrepareAdmissionBatch;
use App\Actions\ReceiveAdmissionBatch;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$emit = static function (array $result, int $exitCode = 0): never {
    fwrite(STDOUT, 'RESULT:'.json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
    fflush(STDOUT);
    exit($exitCode);
};

try {
    $mode = $argv[1] ?? '';
    $fixturePath = $argv[2] ?? '';
    $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
    $nodeConnection = array_merge(config('database.connections.pgsql'), [
        'url' => null,
        'database' => $fixture['node_database'],
    ]);
    config([
        'database.default' => 'pgsql',
        'database.connections.pgsql' => $nodeConnection,
        'database.connections.load_cloud' => array_merge($nodeConnection, [
            'database' => $fixture['cloud_database'] ?? '',
        ]),
        'ingress.mode' => 'node',
        'ingress.node_id' => $fixture['node_id'],
        'ingress.node_sync_key' => $fixture['node_sync_key'] ?? null,
        'ingress.authority_public_key' => $fixture['authority_public_key'],
    ]);
    DB::purge('pgsql');
    DB::purge('load_cloud');
    DB::setDefaultConnection('pgsql');
    DB::selectOne('select 1');

    if ($mode === 'scan') {
        $staff = User::findOrFail($fixture['staff_user_id']);
        $staff->withAccessToken(PersonalAccessToken::findOrFail($fixture['token_id']));
        fwrite(STDOUT, "READY\n");
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'GO') {
            $emit(['ok' => false, 'reason' => 'barrier_not_released'], 1);
        }
        $latencies = [];
        $admitted = 0;
        $failed = 0;
        $firstFailure = null;
        foreach ($fixture['scans'] as $scan) {
            $startedAt = hrtime(true);
            $result = app(AdmitTicket::class)->execute(
                $staff,
                (int) $fixture['event_id'],
                (int) $fixture['access_point_id'],
                $scan['qr_payload'],
                $scan['client_scan_id'],
                'entry',
                false
            );
            $latencies[] = round((hrtime(true) - $startedAt) / 1_000_000, 3);
            if (($result['http_code'] ?? null) === 200 && ($result['status'] ?? null) === 'admitted') {
                $admitted++;
            } else {
                $failed++;
                $firstFailure ??= [
                    'http_code' => $result['http_code'] ?? null,
                    'status' => $result['status'] ?? null,
                ];
            }
        }
        $emit([
            'ok' => $failed === 0 && $admitted === count($fixture['scans']),
            'admitted' => $admitted,
            'failed' => $failed,
            'first_failure' => $firstFailure,
            'latencies_ms' => $latencies,
        ], $failed === 0 ? 0 : 1);
    }

    if ($mode !== 'sync' || ! in_array($fixture['mode'] ?? null, ['commit_then_drop', 'recover'], true)) {
        $emit(['ok' => false, 'reason' => 'invalid_worker_mode'], 1);
    }

    config(['ingress.cloud_sync_url' => 'https://cloud.example/api/v1']);
    $initialBatch = app(PrepareAdmissionBatch::class)->execute((int) $fixture['cloud_event_id']);
    if ($initialBatch === null) {
        $emit(['ok' => false, 'reason' => 'no_pending_admission_batch'], 1);
    }
    $initialBatchId = $initialBatch['batch_id'];
    $initialPackageHash = hash('sha256', $initialBatch['package']);
    $requests = 0;
    $accepted = 0;

    Http::fake(function (Request $request) use ($fixture, &$requests, &$accepted) {
        $requests++;
        if ($request->url() !== 'https://cloud.example/api/v1/node-sync/admissions'
            || ! $request->hasHeader('X-Ingress-Node', $fixture['node_id'])) {
            throw new RuntimeException('Sync command sent an unexpected URL or node identity.');
        }
        if (($fixture['mode'] ?? null) === 'commit_then_drop' && $requests > 1) {
            throw new RuntimeException('The disconnected run should stop after its first request.');
        }
        DB::setDefaultConnection('load_cloud');
        config(['ingress.mode' => 'cloud']);
        try {
            $receipt = app(ReceiveAdmissionBatch::class)->execute($fixture['node_id'], $request->body());
            $accepted++;
        } finally {
            DB::setDefaultConnection('pgsql');
            config(['ingress.mode' => 'node']);
        }

        if (($fixture['mode'] ?? null) === 'commit_then_drop') {
            throw new ConnectionException('Simulated lost response after cloud commit.');
        }

        return Http::response($receipt, 200);
    });

    $exitCode = Artisan::call('ingress:sync-admissions', ['event_id' => (string) $fixture['cloud_event_id']]);
    $nodeSynced = (int) DB::table('node_synced_admissions')
        ->whereIn('admission_id', function ($query) use ($fixture) {
            $query->select('admissions.id')->from('admissions')
                ->join('tickets', 'tickets.id', '=', 'admissions.ticket_id')
                ->join('registrations', 'registrations.id', '=', 'tickets.registration_id')
                ->where('registrations.event_id', $fixture['local_event_id']);
        })->count();
    $pendingBatches = (int) DB::table('node_admission_batches')
        ->where('event_id', $fixture['local_event_id'])->count();
    $cloudAdmissions = (int) DB::connection('load_cloud')->table('admissions')
        ->join('tickets', 'tickets.id', '=', 'admissions.ticket_id')
        ->join('registrations', 'registrations.id', '=', 'tickets.registration_id')
        ->where('registrations.event_id', $fixture['cloud_event_id'])->count();
    $cloudBatches = (int) DB::connection('load_cloud')->table('cloud_admission_batches')
        ->where('event_id', $fixture['cloud_event_id'])->count();
    $expectedExit = ($fixture['mode'] ?? null) === 'commit_then_drop' ? 1 : 0;
    $emit([
        'ok' => $exitCode === $expectedExit,
        'exit_code' => $exitCode,
        'requests' => $requests,
        'cloud_accepted_requests' => $accepted,
        'initial_batch_id' => $initialBatchId,
        'initial_package_sha256' => $initialPackageHash,
        'node_synced_admissions' => $nodeSynced,
        'pending_batches' => $pendingBatches,
        'cloud_admissions' => $cloudAdmissions,
        'cloud_batches' => $cloudBatches,
    ], $exitCode === $expectedExit ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    $emit(['ok' => false, 'reason' => 'exception', 'detail' => $error->getMessage()], 1);
}
