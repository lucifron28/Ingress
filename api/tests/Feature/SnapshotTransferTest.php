<?php

namespace Tests\Feature;

use App\Actions\ExportSnapshot;
use App\Actions\ImportReceivedSnapshot;
use App\Actions\IssueImportReceipt;
use App\Actions\ReceiveSnapshot;
use App\Actions\SnapshotEnvelope;
use App\Http\Controllers\Web\OrganizerEventController;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SnapshotTransferTest extends TestCase
{
    use RefreshDatabase;

    private function sampleSnapshot(int $version, int $eventId): array
    {
        return [
            'schema_version' => 1, 'snapshot_id' => (string) Str::uuid(), 'version' => $version,
            'event' => [
                'id' => $eventId, 'title' => 'Event', 'slug' => 'event', 'description' => null,
                'capacity' => 20, 'status' => 'published', 'starts_at' => now()->toISOString(),
                'ends_at' => now()->addDay()->toISOString(), 'registration_opens_at' => now()->subDay()->toISOString(),
                'registration_closes_at' => now()->addDay()->toISOString(), 'registration_closed_at' => null,
                'venue' => ['id' => 1, 'name' => 'Hall', 'code' => 'HALL', 'location' => null],
                'organizer' => ['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'organizer'],
            ],
            'access_points' => [], 'staff' => [], 'registrations' => [],
        ];
    }

    private function cloudEvent(): array
    {
        config(['ingress.mode' => 'cloud']);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $student = User::factory()->create(['name' => 'Private Attendee', 'role' => User::ROLE_STUDENT]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'location' => 'Campus', 'default_capacity' => 20]);
        $event = Event::create([
            'organizer_id' => $owner->id, 'venue_id' => $venue->id, 'title' => 'Event', 'slug' => 'event',
            'capacity' => 20, 'registration_opens_at' => now()->subDay(), 'registration_closes_at' => now()->addDay(),
            'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => Event::STATUS_PUBLISHED,
        ]);
        $qr = $this->actingAs($student)->postJson("/api/v1/events/{$event->id}/registration")->assertCreated()->json('qr_payload');
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration")->assertRedirect();
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        $this->assertSame(0, Artisan::call('ingress:register-node', ['node_id' => 'node-1']));
        $this->assertSame(1, preg_match('/INGRESS_NODE_SYNC_KEY=([A-Za-z0-9+\/=]+)/', Artisan::output(), $match));

        return [$event, $owner, $qr, $match[1]];
    }

    public function test_encrypted_transfer_and_signed_receipt_across_separate_databases(): void
    {
        [$event, $owner, $qr, $secret] = $this->cloudEvent();
        $response = $this->actingAs($owner)->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-1'])
            ->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $sealed = $response->getContent();
        $this->assertStringNotContainsString('Private Attendee', $sealed);
        $this->assertStringNotContainsString(explode(':', $qr)[3], $sealed);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $cloudConnection = DB::getDefaultConnection();
        $cloudEncrypter = Crypt::getFacadeRoot();
        $nodeDatabase = getenv('INGRESS_TEST_NODE_DB');
        if (is_string($nodeDatabase) && DB::connection()->getDriverName() === 'pgsql') {
            $this->assertMatchesRegularExpression('/^ingress_test_node_[a-z0-9_]+$/', $nodeDatabase);
            $nodeConfig = config('database.connections.'.$cloudConnection);
            $nodeConfig['database'] = $nodeDatabase;
            $nodeConfig['url'] = null;
        } else {
            $nodeConfig = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }
        config(['database.connections.node_test' => $nodeConfig]);
        DB::setDefaultConnection('node_test');
        Artisan::call('migrate', ['--database' => 'node_test', '--force' => true]);
        DB::connection('node_test')->beginTransaction();
        try {
            Crypt::swap(new Encrypter(random_bytes(32), 'AES-256-CBC'));
            config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $secret]);
            $receipt = app(ReceiveSnapshot::class)->execute($sealed);
            $this->assertSame($receipt, app(ReceiveSnapshot::class)->execute($sealed));
            $this->assertDatabaseCount('received_snapshots', 1);
            $this->assertDatabaseCount('events', 0);
            $this->assertDatabaseCount('admissions', 0);
            $stored = DB::table('received_snapshots')->first();
            $this->assertStringNotContainsString('Private Attendee', $stored->payload_ciphertext);
            $this->assertStringContainsString('Private Attendee', Crypt::decryptString($stored->payload_ciphertext));
            try {
                app(IssueImportReceipt::class)->execute($event->id);
                $this->fail('A staged snapshot must not produce an import receipt.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('node_event_imports', 0);
            }
            $importedId = app(ImportReceivedSnapshot::class)->execute($event->id);
            $importReceipt = app(IssueImportReceipt::class)->execute($event->id);
            $this->assertSame($importReceipt, app(IssueImportReceipt::class)->execute($event->id));
            $this->assertSame(0, Artisan::call('ingress:import-snapshot', ['event_id' => (string) $event->id]));
            $this->assertStringContainsString($importReceipt, Artisan::output());
            $this->assertDatabaseHas('events', ['id' => $importedId, 'title' => $event->title]);
            $ticket = Ticket::firstOrFail();
            $this->assertTrue($ticket->verifySecret(explode(':', $qr)[3]));
            $this->assertNull($ticket->encrypted_secret);
            $this->assertSame($importedId, app(ImportReceivedSnapshot::class)->execute($event->id));
            $path = tempnam(sys_get_temp_dir(), 'ingress-test-');
            try {
                file_put_contents($path, $sealed);
                $this->assertSame(0, Artisan::call('ingress:receive-snapshot', ['path' => $path]));
                $this->assertStringContainsString($receipt, Artisan::output());
            } finally {
                unlink($path);
            }
        } finally {
            DB::connection('node_test')->rollBack();
            DB::setDefaultConnection($cloudConnection);
            DB::disconnect('node_test');
            Crypt::swap($cloudEncrypter);
            config(['ingress.mode' => 'cloud', 'ingress.node_id' => null, 'ingress.node_sync_key' => null]);
        }
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/snapshot/import-receipt", ['receipt' => $importReceipt])
            ->assertSessionHasErrors('transfer');
        $this->post("/organizer/events/{$event->id}/snapshot/import-receipt", ['receipt' => $receipt])
            ->assertSessionHasErrors('transfer');
        $this->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => $importReceipt])
            ->assertSessionHasErrors('transfer');
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => $receipt])->assertRedirect();
        $this->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => $receipt])->assertRedirect();
        $this->assertDatabaseCount('event_snapshot_receipts', 1);
        $this->assertDatabaseHas('event_snapshot_receipts', ['node_id' => 'node-1']);
        $tamperedImport = substr($importReceipt, 0, -1).(substr($importReceipt, -1) === 'a' ? 'b' : 'a');
        $this->post("/organizer/events/{$event->id}/snapshot/import-receipt", ['receipt' => $tamperedImport])->assertSessionHasErrors('transfer');
        $this->assertDatabaseCount('event_snapshot_import_receipts', 0);
        $this->post("/organizer/events/{$event->id}/snapshot/import-receipt", ['receipt' => $importReceipt])->assertSessionHasNoErrors();
        $this->post("/organizer/events/{$event->id}/snapshot/import-receipt", ['receipt' => $importReceipt])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('event_snapshot_import_receipts', 1);
        $this->get("/organizer/events/{$event->id}")->assertSee('Import confirmed by node node-1.');
        $this->get("/organizer/events/{$event->id}")->assertOk()->assertSee('Received by node node-1.');
        $tampered = substr($receipt, 0, -1).(substr($receipt, -1) === 'a' ? 'b' : 'a');
        $this->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => $tampered])
            ->assertSessionHasErrors('transfer');
        $this->assertDatabaseCount('event_snapshot_receipts', 1);
        $this->post("/organizer/events/{$event->id}/discard-snapshot")->assertRedirect();
        $this->post("/organizer/events/{$event->id}/snapshot/import-receipt", ['receipt' => $importReceipt])->assertSessionHasErrors('transfer');
        $this->get("/organizer/events/{$event->id}")->assertDontSee('Import confirmed by node node-1.');
        $this->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => $receipt])
            ->assertSessionHasErrors('transfer');
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        $this->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-1'])->assertOk();
        $this->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => $receipt])
            ->assertSessionHasErrors('transfer');
        $this->assertDatabaseCount('event_snapshot_receipts', 1);
    }

    public function test_cloud_snapshot_can_be_reprepared_and_refreshed_on_a_separate_node(): void
    {
        [$event, $owner, $qr, $secret] = $this->cloudEvent();
        $first = $this->actingAs($owner)->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-1'])->assertOk()->getContent();
        $cloudConnection = DB::getDefaultConnection();
        $cloudEncrypter = Crypt::getFacadeRoot();
        $nodeEncrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $nodeDatabase = getenv('INGRESS_TEST_NODE_DB');
        if (is_string($nodeDatabase) && DB::connection()->getDriverName() === 'pgsql') {
            $this->assertMatchesRegularExpression('/^ingress_test_node_[a-z0-9_]+$/', $nodeDatabase);
            $nodeConfig = config('database.connections.'.$cloudConnection);
            $nodeConfig['database'] = $nodeDatabase;
            $nodeConfig['url'] = null;
        } else {
            $nodeConfig = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }
        config(['database.connections.refresh_node' => $nodeConfig]);
        DB::setDefaultConnection('refresh_node');
        Artisan::call('migrate', ['--database' => 'refresh_node', '--force' => true]);
        DB::connection('refresh_node')->beginTransaction();
        try {
            Crypt::swap($nodeEncrypter);
            config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $secret]);
            app(ReceiveSnapshot::class)->execute($first);
            $localId = app(ImportReceivedSnapshot::class)->execute($event->id);
            $this->assertDatabaseCount('access_points', 0);

            DB::setDefaultConnection($cloudConnection);
            Crypt::swap($cloudEncrypter);
            config(['ingress.mode' => 'cloud', 'ingress.node_id' => null, 'ingress.node_sync_key' => null]);
            $this->actingAs($owner)->post("/organizer/events/{$event->id}/discard-snapshot")->assertRedirect();
            $this->post("/organizer/events/{$event->id}/access-points", ['name' => 'New Gate', 'code' => 'NEW'])->assertSessionHas('success');
            $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
            $second = $this->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-1'])->assertOk()->getContent();

            DB::setDefaultConnection('refresh_node');
            Crypt::swap($nodeEncrypter);
            config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $secret]);
            app(ReceiveSnapshot::class)->execute($second);
            try {
                app(IssueImportReceipt::class)->execute($event->id);
                $this->fail('A stale import must not produce a receipt.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
            $this->assertSame($localId, app(ImportReceivedSnapshot::class)->execute($event->id));
            $this->assertDatabaseHas('access_points', ['event_id' => $localId, 'name' => 'New Gate']);
            $this->assertDatabaseHas('node_event_imports', ['cloud_event_id' => $event->id, 'version' => 2]);
            $this->assertTrue(Ticket::firstOrFail()->verifySecret(explode(':', $qr)[3]));
            $this->assertDatabaseCount('admissions', 0);
        } finally {
            DB::connection('refresh_node')->rollBack();
            DB::setDefaultConnection($cloudConnection);
            DB::disconnect('refresh_node');
            Crypt::swap($cloudEncrypter);
            config(['ingress.mode' => 'cloud', 'ingress.node_id' => null, 'ingress.node_sync_key' => null]);
        }
    }

    public function test_envelope_rejects_wrong_key_wrong_node_and_tampering(): void
    {
        $envelope = app(SnapshotEnvelope::class);
        $secret = base64_encode(random_bytes(32));
        $json = json_encode($this->sampleSnapshot(1, 1), JSON_THROW_ON_ERROR);
        $sealed = $envelope->seal('node-1', $secret, $json);
        $altered = json_decode(base64_decode($sealed, true), true, 512, JSON_THROW_ON_ERROR);
        $altered['value'][0] = $altered['value'][0] === 'A' ? 'B' : 'A';
        $altered = base64_encode(json_encode($altered, JSON_THROW_ON_ERROR));
        foreach ([['node-2', $secret, $sealed], ['node-1', base64_encode(random_bytes(32)), $sealed],
            ['node-1', $secret, substr($sealed, 0, -1).'!'], ['node-1', $secret, $altered]] as [$node, $key, $message]) {
            try {
                $envelope->open($node, $key, $message);
                $this->fail('Invalid transfer was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_receipt_is_recoverable_after_node_process_restart(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'ingress-node-');
        $package = tempnam(sys_get_temp_dir(), 'ingress-package-');
        $originalConnection = DB::getDefaultConnection();
        $secret = base64_encode(random_bytes(32));
        $json = json_encode($this->sampleSnapshot(1, 99), JSON_THROW_ON_ERROR);
        $sealed = app(SnapshotEnvelope::class)->seal('node-restart', $secret, $json);
        try {
            file_put_contents($package, $sealed);
            config(['database.connections.restart_node' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true]]);
            DB::setDefaultConnection('restart_node');
            Artisan::call('migrate', ['--database' => 'restart_node', '--force' => true]);
            config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-restart', 'ingress.node_sync_key' => $secret]);
            $receipt = app(ReceiveSnapshot::class)->execute($sealed);
            DB::disconnect('restart_node');
            $process = new Process([PHP_BINARY, 'artisan', 'ingress:receive-snapshot', $package], base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database,
                'DB_URL' => '', 'INGRESS_RUNTIME' => 'node', 'INGRESS_NODE_ID' => 'node-restart', 'INGRESS_NODE_SYNC_KEY' => $secret,
            ]);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString($receipt, $process->getOutput());
            $this->assertDatabaseCount('received_snapshots', 1);
        } finally {
            DB::disconnect('restart_node');
            DB::setDefaultConnection($originalConnection);
            unlink($database);
            unlink($package);
        }
    }

    public function test_node_rejects_stale_and_conflicting_versions_without_changing_staged_data(): void
    {
        $secret = base64_encode(random_bytes(32));
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $secret]);
        $envelope = app(SnapshotEnvelope::class);
        $message = function (int $version) use ($envelope, $secret): string {
            $json = json_encode($this->sampleSnapshot($version, 123), JSON_THROW_ON_ERROR);

            return $envelope->seal('node-1', $secret, $json);
        };
        $first = $message(1);
        $second = $message(2);
        app(ReceiveSnapshot::class)->execute($first);
        $receipt = app(ReceiveSnapshot::class)->execute($second);
        $this->assertSame($receipt, app(ReceiveSnapshot::class)->execute($second));
        foreach ([$first, $message(2)] as $invalid) {
            try {
                app(ReceiveSnapshot::class)->execute($invalid);
                $this->fail('Stale or conflicting snapshot was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('received_snapshots', 2);
        $this->assertSame(2, (int) DB::table('received_event_heads')->where('event_id', 123)->value('version'));
        DB::table('received_event_heads')->where('event_id', 123)->update(['version' => 0]);
        try {
            app(ReceiveSnapshot::class)->execute($second);
            $this->fail('An inconsistent version head was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseCount('events', 0);
    }

    public function test_other_organizer_cannot_export_or_confirm_receipt(): void
    {
        [$event] = $this->cloudEvent();
        $other = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->actingAs($other)->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-1'])->assertForbidden();
        $this->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => 'invalid'])->assertForbidden();
        $this->post("/organizer/events/{$event->id}/snapshot/import-receipt", ['receipt' => 'invalid'])->assertForbidden();
        $this->assertNull(DB::table('event_snapshots')->where('event_id', $event->id)->value('target_node_id'));
        $this->assertDatabaseCount('event_snapshot_receipts', 0);
    }

    public function test_conflicting_stored_receipt_is_not_silently_accepted(): void
    {
        [$event, $owner, $qr, $secret] = $this->cloudEvent();
        $this->assertSame(0, Artisan::call('ingress:register-node', ['node_id' => 'node-2']));
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-1'])->assertOk();
        $snapshot = DB::table('event_snapshots')->where('event_id', $event->id)->first();
        $receipt = app(SnapshotEnvelope::class)->receipt([
            'node_id' => 'node-1', 'snapshot_id' => $snapshot->id, 'event_id' => $event->id,
            'version' => (int) $snapshot->version, 'sha256' => $snapshot->sha256,
        ], $secret);
        DB::table('event_snapshot_receipts')->insert([
            'snapshot_id' => $snapshot->id, 'node_id' => 'node-2', 'sha256' => $snapshot->sha256, 'received_at' => now(),
        ]);
        $this->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => $receipt])->assertSessionHasErrors('transfer');
        $this->assertDatabaseHas('event_snapshot_receipts', ['snapshot_id' => $snapshot->id, 'node_id' => 'node-2']);
        $this->get("/organizer/events/{$event->id}")->assertOk()->assertDontSee('Received by node node-2.');
    }

    public function test_receipt_from_revoked_node_is_rejected(): void
    {
        [$event, $owner, $qr, $secret] = $this->cloudEvent();
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-1'])->assertOk();
        $snapshot = DB::table('event_snapshots')->where('event_id', $event->id)->first();
        $receipt = app(SnapshotEnvelope::class)->receipt([
            'node_id' => 'node-1', 'snapshot_id' => $snapshot->id, 'event_id' => $event->id,
            'version' => (int) $snapshot->version, 'sha256' => $snapshot->sha256,
        ], $secret);
        $importReceipt = app(SnapshotEnvelope::class)->importReceipt([
            'node_id' => 'node-1', 'snapshot_id' => $snapshot->id, 'event_id' => $event->id,
            'version' => (int) $snapshot->version, 'sha256' => $snapshot->sha256,
        ], $secret);
        $this->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => $receipt])->assertSessionHasNoErrors();
        $this->assertSame(0, Artisan::call('ingress:revoke-node', ['node_id' => 'node-1']));
        $this->post("/organizer/events/{$event->id}/snapshot/import-receipt", ['receipt' => $importReceipt])->assertSessionHasErrors('transfer');
        $this->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => $receipt])->assertSessionHasErrors('transfer');
        $this->assertDatabaseCount('event_snapshot_receipts', 1);
        $this->assertDatabaseCount('event_snapshot_import_receipts', 0);
    }

    public function test_export_requires_https_outside_local_or_testing(): void
    {
        [$event, $owner] = $this->cloudEvent();
        $this->actingAs($owner);
        $this->app->detectEnvironment(fn () => 'production');
        $request = Request::create('http://example.test/export', 'POST', ['node_id' => 'node-1']);
        try {
            app(OrganizerEventController::class)->exportSnapshot($request, $event, app(ExportSnapshot::class));
            $this->fail('Unencrypted export was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_export_is_bound_to_one_node_and_rejects_revoked_nodes(): void
    {
        [$event, $owner] = $this->cloudEvent();
        $this->assertSame(0, Artisan::call('ingress:register-node', ['node_id' => 'node-2']));
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-1'])->assertOk();
        $this->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-2'])
            ->assertSessionHasErrors('transfer');
        $this->assertSame(0, Artisan::call('ingress:revoke-node', ['node_id' => 'node-1']));
        $this->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-1'])
            ->assertSessionHasErrors('transfer');
        $this->assertDatabaseCount('event_snapshot_receipts', 0);
    }
}
