<?php

namespace Tests\Feature;

use App\Actions\AcknowledgeAdmissionBatch;
use App\Actions\ActivateEventAuthority;
use App\Actions\AdmissionSyncEnvelope;
use App\Actions\AuthorityGrant;
use App\Actions\ExportSnapshot;
use App\Actions\ImportReceivedSnapshot;
use App\Actions\IssueImportReceipt;
use App\Actions\PrepareAdmissionBatch;
use App\Actions\ReceiveAdmissionBatch;
use App\Actions\ReceiveSnapshot;
use App\Actions\SnapshotEnvelope;
use App\Http\Controllers\Api\V1\AdmissionSyncController;
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
use Illuminate\Support\Facades\Http;
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

    private function cloudEvent(bool $withStaff = false): array
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
        $cloudStaffId = null;
        if ($withStaff) {
            $staff = User::factory()->create(['role' => User::ROLE_STAFF, 'email' => 'node-operator@example.test']);
            $cloudStaffId = $staff->id;
            $gateId = DB::table('access_points')->insertGetId([
                'event_id' => $event->id, 'name' => 'Gate', 'code' => 'MAIN', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('event_staff')->insert([
                'event_id' => $event->id, 'user_id' => $staff->id, 'access_point_id' => $gateId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $qr = $this->actingAs($student)->postJson("/api/v1/events/{$event->id}/registration")->assertCreated()->json('qr_payload');
        $secondQr = null;
        if ($withStaff) {
            $second = User::factory()->create(['name' => 'Second Attendee', 'role' => User::ROLE_STUDENT]);
            $secondQr = $this->actingAs($second)->postJson("/api/v1/events/{$event->id}/registration")->assertCreated()->json('qr_payload');
        }
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration")->assertRedirect();
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        $this->assertSame(0, Artisan::call('ingress:register-node', ['node_id' => 'node-1']));
        $this->assertSame(1, preg_match('/INGRESS_NODE_SYNC_KEY=([A-Za-z0-9+\/=]+)/', Artisan::output(), $match));

        return [$event, $owner, $qr, $match[1], $cloudStaffId, $secondQr];
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

    public function test_authority_grant_freezes_cloud_and_activates_only_the_imported_node(): void
    {
        [$event, $owner, $qr, $secret, $cloudStaffId, $secondQr] = $this->cloudEvent(true);
        $keypair = sodium_crypto_sign_keypair();
        config([
            'ingress.authority_private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
            'ingress.authority_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        ]);
        $sealed = $this->actingAs($owner)->post("/organizer/events/{$event->id}/snapshot/export", ['node_id' => 'node-1'])->assertOk()->getContent();
        $this->post("/organizer/events/{$event->id}/authority-transfer")->assertSessionHasErrors('transfer');
        $this->assertDatabaseCount('cloud_event_authorities', 0);
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
        config(['database.connections.authority_node' => $nodeConfig]);
        DB::setDefaultConnection('authority_node');
        Artisan::call('migrate', ['--database' => 'authority_node', '--force' => true]);
        DB::connection('authority_node')->beginTransaction();
        try {
            Crypt::swap($nodeEncrypter);
            config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $secret]);
            $receipt = app(ReceiveSnapshot::class)->execute($sealed);
            $localId = app(ImportReceivedSnapshot::class)->execute($event->id);
            $importReceipt = app(IssueImportReceipt::class)->execute($event->id);
            DB::setDefaultConnection($cloudConnection);
            Crypt::swap($cloudEncrypter);
            config(['ingress.mode' => 'cloud', 'ingress.node_id' => null, 'ingress.node_sync_key' => null]);
            $this->actingAs($owner)->post("/organizer/events/{$event->id}/snapshot/receipt", ['receipt' => $receipt])->assertSessionHasNoErrors();
            $this->post("/organizer/events/{$event->id}/snapshot/import-receipt", ['receipt' => $importReceipt])->assertSessionHasNoErrors();
            config(['ingress.authority_public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
            $this->post("/organizer/events/{$event->id}/authority-transfer")->assertSessionHasErrors('transfer');
            $this->assertDatabaseCount('cloud_event_authorities', 0);
            config(['ingress.authority_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair))]);
            $grant = $this->post("/organizer/events/{$event->id}/authority-transfer")->assertOk()->getContent();
            $this->assertSame($grant, $this->post("/organizer/events/{$event->id}/authority-transfer")->assertOk()->getContent());
            $this->post("/organizer/events/{$event->id}/discard-snapshot")->assertSessionHasErrors('event');
            $this->post("/organizer/events/{$event->id}/cancel")->assertSessionHasErrors('event');
            $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'published']);
            $this->assertDatabaseCount('cloud_event_authorities', 1);
            DB::setDefaultConnection('authority_node');
            Crypt::swap($nodeEncrypter);
            config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $secret]);
            $tampered = substr($grant, 0, 5).'x'.substr($grant, 6);
            try {
                app(ActivateEventAuthority::class)->execute($tampered);
                $this->fail('A tampered grant was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('node_event_authorities', 0);
            }
            config(['ingress.authority_public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]);
            try {
                app(ActivateEventAuthority::class)->execute($grant);
                $this->fail('A grant signed by another cloud was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('node_event_authorities', 0);
            }
            config(['ingress.authority_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair))]);
            $wrongNodeGrant = app(AuthorityGrant::class)->sign([
                'protocol' => 1, 'node_id' => 'node-2', 'snapshot_id' => DB::table('node_event_imports')->value('snapshot_id'),
                'event_id' => $event->id, 'version' => 1, 'sha256' => DB::table('node_event_imports')->value('sha256'),
            ]);
            try {
                app(ActivateEventAuthority::class)->execute($wrongNodeGrant);
                $this->fail('A grant for a different node was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('node_event_authorities', 0);
            }
            $testGate = DB::table('access_points')->insertGetId([
                'event_id' => $localId, 'name' => 'Unexpected gate', 'code' => 'UNEXPECTED', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('admissions')->insert([
                'ticket_id' => Ticket::firstOrFail()->id, 'access_point_id' => $testGate,
                'scanned_by_user_id' => DB::table('events')->where('id', $localId)->value('organizer_id'),
                'client_scan_id' => 'unexpected-admission', 'credential_version' => 1,
                'admitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            try {
                app(ActivateEventAuthority::class)->execute($grant);
                $this->fail('An event with prior node admissions was activated.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('node_event_authorities', 0);
            }
            DB::table('admissions')->where('client_scan_id', 'unexpected-admission')->delete();
            DB::table('access_points')->where('id', $testGate)->delete();
            $this->assertSame($localId, app(ActivateEventAuthority::class)->execute($grant));
            $this->assertSame($localId, app(ActivateEventAuthority::class)->execute($grant));
            $path = tempnam(sys_get_temp_dir(), 'ingress-grant-');
            try {
                file_put_contents($path, $grant);
                $this->assertSame(0, Artisan::call('ingress:activate-event', ['path' => $path]));
            } finally {
                unlink($path);
            }
            $this->assertSame($localId, app(ImportReceivedSnapshot::class)->execute($event->id));
            $this->assertDatabaseCount('node_event_authorities', 1);
            $this->assertSame(0, Artisan::call('ingress:provision-node-staff', ['cloud_user_id' => (string) $cloudStaffId]));
            preg_match('/NODE_STAFF_PASSWORD=([a-f0-9]{32})/', Artisan::output(), $password);
            app('auth')->forgetGuards();
            $staffToken = $this->postJson('/api/v1/auth/node-staff/login', [
                'email' => 'node-operator@example.test', 'password' => $password[1],
            ])->assertOk()->json('token');
            $gateId = DB::table('event_staff')->where('event_id', $localId)->value('access_point_id');
            $scan = ['access_point_id' => $gateId, 'qr_payload' => $qr, 'client_scan_id' => 'integrated-scan'];
            $this->withToken($staffToken)->postJson("/api/v1/staff/events/{$localId}/admissions/preview", $scan)
                ->assertOk()->assertJsonPath('status', 'valid');
            $this->assertDatabaseCount('admissions', 0);
            $this->withToken($staffToken)->postJson("/api/v1/staff/events/{$localId}/admissions", $scan)
                ->assertOk()->assertJsonPath('status', 'admitted');
            $this->assertDatabaseCount('admissions', 1);
            $batch = app(PrepareAdmissionBatch::class)->execute($event->id);
            $this->assertNotNull($batch);
            $this->assertSame($batch, app(PrepareAdmissionBatch::class)->execute($event->id));
            $secondScan = ['access_point_id' => $gateId, 'qr_payload' => $secondQr, 'client_scan_id' => 'second-scan'];
            $this->withToken($staffToken)->postJson("/api/v1/staff/events/{$localId}/admissions", $secondScan)
                ->assertOk()->assertJsonPath('status', 'admitted');
            $this->assertSame($batch, app(PrepareAdmissionBatch::class)->execute($event->id));
            config(['ingress.cloud_sync_url' => 'http://cloud.example/api/v1']);
            $httpSequence = Http::sequence()->push([], 302, ['Location' => 'https://other.example/']);
            Http::fake(['cloud.example/*' => $httpSequence]);
            $this->assertSame(1, Artisan::call('ingress:sync-admissions', ['event_id' => (string) $event->id]));
            Http::assertNothingSent();
            config(['ingress.cloud_sync_url' => 'https://cloud.example/api/v1']);
            $this->assertSame(1, Artisan::call('ingress:sync-admissions', ['event_id' => (string) $event->id]));
            Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example/api/v1/node-sync/admissions'
                && $request->hasHeader('X-Ingress-Node', 'node-1') && ! $request->hasHeader('Authorization'));
            $this->assertSame($batch, app(PrepareAdmissionBatch::class)->execute($event->id));
            $bad = json_decode(app(AdmissionSyncEnvelope::class)->open($batch['package'], $secret), true, 16, JSON_THROW_ON_ERROR);
            $invalid = $bad['admissions'][0];
            $invalid['admission_id']++;
            $invalid['ticket_id'] = 999999;
            $invalid['scan_id'] = 'bad-scan';
            $bad['admissions'][] = $invalid;
            $badPackage = app(AdmissionSyncEnvelope::class)->seal(json_encode($bad, JSON_THROW_ON_ERROR), $secret);
            DB::setDefaultConnection($cloudConnection);
            Crypt::swap($cloudEncrypter);
            config(['ingress.mode' => 'cloud', 'ingress.node_id' => null, 'ingress.node_sync_key' => null]);
            $headers = ['HTTP_X_INGRESS_NODE' => 'node-1', 'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'];
            $this->call('POST', '/api/v1/node-sync/admissions', [], [], [], $headers, $badPackage)->assertStatus(422);
            $this->assertDatabaseCount('admissions', 0);
            $this->call('POST', '/api/v1/node-sync/admissions', [], [], [], $headers, substr($batch['package'], 0, -1).'!')->assertStatus(422);
            $wrongHeaders = $headers;
            $wrongHeaders['HTTP_X_INGRESS_NODE'] = 'node-2';
            $this->call('POST', '/api/v1/node-sync/admissions', [], [], [], $wrongHeaders, $batch['package'])->assertStatus(422);
            $cloudResponse = $this->call('POST', '/api/v1/node-sync/admissions', [], [], [], $headers, $batch['package'])->assertOk();
            $this->assertDatabaseCount('admissions', 1);
            $cloudReceipt = $cloudResponse->json('receipt');
            $this->call('POST', '/api/v1/node-sync/admissions', [], [], [], $headers, $batch['package'])
                ->assertOk()->assertJsonPath('receipt', $cloudReceipt);
            $this->assertDatabaseCount('admissions', 1);
            $this->call('POST', '/api/v1/node-sync/admissions', [], [], [], $headers, $badPackage)->assertStatus(422);
            $this->assertDatabaseCount('admissions', 1);
            DB::setDefaultConnection('authority_node');
            Crypt::swap($nodeEncrypter);
            config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $secret]);
            try {
                app(AcknowledgeAdmissionBatch::class)->execute($event->id, $batch['batch_id'], str_repeat('0', 64));
                $this->fail('A forged receipt was accepted.');
            } catch (ValidationException) {
                $this->assertSame($batch, app(PrepareAdmissionBatch::class)->execute($event->id));
            }
            $httpSequence->push(['batch_id' => $batch['batch_id'], 'receipt' => $cloudReceipt], 200)->push([], 503);
            $this->assertSame(1, Artisan::call('ingress:sync-admissions', ['event_id' => (string) $event->id]));
            $this->assertDatabaseCount('node_synced_admissions', 1);
            $nextBatch = app(PrepareAdmissionBatch::class)->execute($event->id);
            $this->assertNotNull($nextBatch);
            $this->assertNotSame($batch['batch_id'], $nextBatch['batch_id']);
            $this->assertSame(1, $nextBatch['count']);
            DB::setDefaultConnection($cloudConnection);
            Crypt::swap($cloudEncrypter);
            config(['ingress.mode' => 'cloud', 'ingress.node_id' => null, 'ingress.node_sync_key' => null]);
            $nextReceipt = $this->call('POST', '/api/v1/node-sync/admissions', [], [], [], $headers, $nextBatch['package'])->assertOk()->json('receipt');
            $this->assertDatabaseCount('admissions', 2);
            DB::setDefaultConnection('authority_node');
            Crypt::swap($nodeEncrypter);
            config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $secret]);
            app(AcknowledgeAdmissionBatch::class)->execute($event->id, $nextBatch['batch_id'], $nextReceipt);
            $this->assertDatabaseCount('node_synced_admissions', 2);
            $this->assertNull(app(PrepareAdmissionBatch::class)->execute($event->id));
            DB::setDefaultConnection($cloudConnection);
            Crypt::swap($cloudEncrypter);
            config(['ingress.mode' => 'cloud', 'ingress.node_id' => null, 'ingress.node_sync_key' => null]);
            $this->assertSame(0, Artisan::call('ingress:revoke-node', ['node_id' => 'node-1']));
            $this->call('POST', '/api/v1/node-sync/admissions', [], [], [], $headers, $batch['package'])->assertStatus(422);
            DB::setDefaultConnection('authority_node');
            Crypt::swap($nodeEncrypter);
            config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1', 'ingress.node_sync_key' => $secret]);
            $new = $this->sampleSnapshot(2, $event->id);
            $newJson = json_encode($new, JSON_THROW_ON_ERROR);
            try {
                app(ReceiveSnapshot::class)->execute(app(SnapshotEnvelope::class)->seal('node-1', $secret, $newJson));
                $this->fail('An activated event accepted a new snapshot.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('received_snapshots', 1);
            }
        } finally {
            DB::connection('authority_node')->rollBack();
            DB::setDefaultConnection($cloudConnection);
            DB::disconnect('authority_node');
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

    public function test_sync_requires_https_outside_local_or_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $request = Request::create('http://example.test/api/v1/node-sync/admissions', 'POST');
        try {
            app(AdmissionSyncController::class)->receive($request, app(ReceiveAdmissionBatch::class));
            $this->fail('Unencrypted synchronization was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
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
