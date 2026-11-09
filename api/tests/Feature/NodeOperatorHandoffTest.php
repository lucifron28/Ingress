<?php

namespace Tests\Feature;

use App\Actions\ActivateEventAuthority;
use App\Actions\AuthorityGrant;
use App\Actions\ImportReceivedSnapshot;
use App\Actions\IssueImportReceipt;
use App\Actions\ReceiveSnapshot;
use App\Actions\SnapshotEnvelope;
use App\Models\NodeOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class NodeOperatorHandoffTest extends TestCase
{
    use RefreshDatabase;

    private string $transferKey;

    private string $authoritySecretKey;

    protected function setUp(): void
    {
        parent::setUp();

        $keyPair = sodium_crypto_sign_keypair();
        $this->transferKey = base64_encode(random_bytes(32));
        $this->authoritySecretKey = base64_encode(sodium_crypto_sign_secretkey($keyPair));

        config([
            'ingress.mode' => 'node',
            'ingress.node_id' => 'node-1',
            'ingress.node_sync_key' => $this->transferKey,
            'ingress.authority_private_key' => $this->authoritySecretKey,
            'ingress.authority_public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
            'ingress.cloud_sync_url' => 'https://cloud.example/api/v1',
            'ingress.operator_allowed_cidrs' => ['127.0.0.1/32', '::1/128'],
            'app.url' => 'https://node.example.edu',
            'session.driver' => 'database',
            'session.secure' => true,
            'session.http_only' => true,
            'session.encrypt' => true,
            'session.same_site' => 'strict',
            'cache.default' => 'database',
        ]);
    }

    public function test_handoff_is_node_operator_only_and_shows_staged_metadata_without_payload_or_grant(): void
    {
        $this->stageSnapshot();

        $this->get('/node/operator/handoff')->assertRedirect('/node/operator/login');

        $operator = NodeOperator::factory()->create();
        $response = $this->actingAs($operator, 'node_operator')
            ->get('/node/operator/handoff')
            ->assertOk()
            ->assertSee('Event handoff')
            ->assertSee('node-1')
            ->assertSee('Cloud event 10')
            ->assertSee('Staged; import required')
            ->assertSee('Handoff actions are disabled')
            ->assertDontSee('Import snapshot and create receipt')
            ->assertDontSee('test-payload-private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->from('/node/operator/handoff')->post('/node/operator/handoff/events/10/import', [
            'confirmation' => 'IMPORT',
        ])->assertRedirect('/node/operator/handoff');
        $this->assertDatabaseCount('node_event_imports', 0);

        config(['ingress.authority_private_key' => null]);
        $this->actingAs($operator, 'node_operator')->get('/node/operator/handoff')
            ->assertSee('Import snapshot and create receipt');

        config(['app.url' => 'http://node.example.edu']);
        $this->actingAs($operator, 'node_operator')->get('/node/operator/handoff')
            ->assertSee('Handoff actions are disabled')
            ->assertDontSee('Import snapshot and create receipt');
        config(['app.url' => 'https://node.example.edu']);

        config(['ingress.mode' => 'cloud']);
        $this->get('/node/operator/handoff')->assertStatus(503);
        $this->assertDatabaseCount('node_event_authorities', 0);
    }

    public function test_operator_dashboard_shows_verified_status_and_no_sync_action_without_pending_records(): void
    {
        $eventId = $this->activateCurrentEvent();
        DB::table('events')->where('id', $eventId)->update(['title' => '<script>alert(1)</script>']);
        $operator = NodeOperator::factory()->create();

        $response = $this->actingAs($operator, 'node_operator')
            ->get('/node/operator')
            ->assertOk()
            ->assertSee('Node identity and event authority verified')
            ->assertSee('Node ID')
            ->assertSee('node-1')
            ->assertSee('Snapshot version 1')
            ->assertSee('Most recent acknowledged record')
            ->assertSee('Last sync pass result')
            ->assertSee('No pending records to send')
            ->assertDontSee('Run one bounded sync pass')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('test-payload-private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('node_operator_audits', 0);
    }

    public function test_uncertain_authority_blocks_dashboard_sync_action(): void
    {
        $eventId = $this->activateCurrentEvent();
        DB::table('node_event_authorities')->where('event_id', $eventId)->update(['grant' => 'tampered']);
        $operator = NodeOperator::factory()->create();

        $this->actingAs($operator, 'node_operator')
            ->get('/node/operator')
            ->assertOk()
            ->assertSee('Stop all gates')
            ->assertDontSee('Run one bounded sync pass');
        $this->actingAs($operator, 'node_operator')
            ->post("/node/operator/events/{$eventId}/sync")
            ->assertStatus(409);
        $this->assertDatabaseCount('node_operator_audits', 0);
        Http::assertNothingSent();
    }

    public function test_non_https_node_url_disables_manual_sync(): void
    {
        $eventId = $this->activateCurrentEvent();
        config(['app.url' => 'http://node.example.edu']);
        $operator = NodeOperator::factory()->create();

        $this->actingAs($operator, 'node_operator')
            ->get('/node/operator')
            ->assertOk()
            ->assertSee('HTTPS URL not configured')
            ->assertSee('Not safely configured')
            ->assertDontSee('Run one bounded sync pass');
        $this->actingAs($operator, 'node_operator')
            ->post("/node/operator/events/{$eventId}/sync")
            ->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_snapshot_upload_uses_the_existing_receiver_and_returns_a_no_store_receipt(): void
    {
        Storage::fake('local');
        [$sealed, $payload] = $this->snapshotPackage();
        $operator = NodeOperator::factory()->create();
        config(['ingress.authority_private_key' => null]);

        $response = $this->actingAs($operator, 'node_operator')
            ->post('/node/operator/handoff/snapshot', [
                'snapshot' => UploadedFile::fake()->createWithContent('event-snapshot.bin', $sealed),
            ])
            ->assertOk()
            ->assertSee('Encrypted snapshot staged')
            ->assertSee('staging receipt')
            ->assertSee('Send this staging receipt to the cloud organizer')
            ->assertDontSee('test-payload-private');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(1, preg_match('/<textarea id="handoff-receipt"[^>]*>([^<]+)<\/textarea>/', $response->getContent(), $matches));
        $receipt = trim($matches[1]);
        $metadata = app(SnapshotEnvelope::class)->verifyReceipt($receipt, $this->transferKey);
        $this->assertSame(10, $metadata['event_id']);
        $this->assertSame(1, $metadata['version']);
        $this->assertSame(hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $metadata['sha256']);
        $this->assertDatabaseHas('received_snapshots', [
            'id' => $metadata['snapshot_id'],
            'event_id' => 10,
            'version' => 1,
        ]);
        $ciphertext = DB::table('received_snapshots')->where('id', $metadata['snapshot_id'])->value('payload_ciphertext');
        $this->assertStringNotContainsString('test-payload-private', $ciphertext);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $retry = $this->post('/node/operator/handoff/snapshot', [
            'snapshot' => UploadedFile::fake()->createWithContent('event-snapshot.bin', $sealed),
        ])->assertOk();
        $this->assertStringContainsString($receipt, $retry->getContent());
        $this->assertDatabaseCount('received_snapshots', 1);
        $this->assertDatabaseHas('node_operator_audits', [
            'actor_id' => $operator->id,
            'action' => 'snapshot.received',
            'node_id' => 'node-1',
            'outcome' => 'succeeded',
        ]);
    }

    public function test_snapshot_upload_rejects_tampered_wrong_node_and_oversized_files(): void
    {
        [$sealed] = $this->snapshotPackage();
        $wrongNode = app(SnapshotEnvelope::class)->seal('node-2', $this->transferKey, json_encode($this->payload(), JSON_THROW_ON_ERROR));
        $operator = NodeOperator::factory()->create();
        config(['ingress.authority_private_key' => null]);
        $this->actingAs($operator, 'node_operator');

        $this->from('/node/operator/handoff')->post('/node/operator/handoff/snapshot', [
            'snapshot' => UploadedFile::fake()->createWithContent('tampered.bin', substr($sealed, 0, -4).'xxxx'),
        ])->assertRedirect('/node/operator/handoff');
        $this->assertDatabaseCount('received_snapshots', 0);

        $this->from('/node/operator/handoff')->post('/node/operator/handoff/snapshot', [
            'snapshot' => UploadedFile::fake()->createWithContent('wrong-node.bin', $wrongNode),
        ])->assertRedirect('/node/operator/handoff');
        $this->assertDatabaseCount('received_snapshots', 0);

        $this->from('/node/operator/handoff')->post('/node/operator/handoff/snapshot', [
            'snapshot' => UploadedFile::fake()->create('too-large.bin', 16385),
        ])->assertSessionHasErrors('snapshot');
        $this->assertDatabaseCount('received_snapshots', 0);
        $this->assertDatabaseHas('node_operator_audits', ['action' => 'snapshot.received', 'outcome' => 'rejected']);
    }

    public function test_import_is_idempotent_and_returns_the_receipt_required_by_the_cloud_organizer(): void
    {
        $this->stageSnapshot();
        $operator = NodeOperator::factory()->create();
        config(['ingress.authority_private_key' => null]);

        $this->actingAs($operator, 'node_operator');
        $path = '/node/operator/handoff/events/10/import';
        $this->from('/node/operator/handoff')->post($path)->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('node_event_imports', 0);
        $this->assertDatabaseCount('node_event_authorities', 0);

        $response = $this->actingAs($operator, 'node_operator')
            ->post($path, ['confirmation' => 'IMPORT'])
            ->assertOk()
            ->assertSee('Snapshot imported on this node')
            ->assertSee('Send this import receipt to the cloud organizer')
            ->assertSee('Admission remains disabled')
            ->assertDontSee('authority grant was activated');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(1, preg_match('/<textarea id="handoff-receipt"[^>]*>([^<]+)<\/textarea>/', $response->getContent(), $matches));
        $firstReceipt = trim($matches[1]);
        $localEventId = (int) DB::table('node_event_imports')->where('cloud_event_id', 10)->value('event_id');
        $this->assertGreaterThan(0, $localEventId);
        $this->assertSame(
            $firstReceipt,
            app(SnapshotEnvelope::class)->importReceipt([
                'node_id' => 'node-1',
                'snapshot_id' => DB::table('node_event_imports')->where('cloud_event_id', 10)->value('snapshot_id'),
                'event_id' => 10,
                'version' => 1,
                'sha256' => DB::table('node_event_imports')->where('cloud_event_id', 10)->value('sha256'),
            ], $this->transferKey),
        );
        $this->assertDatabaseCount('node_event_authorities', 0);

        $secondResponse = $this->post($path, ['confirmation' => 'IMPORT'])->assertOk();
        $this->assertStringContainsString($firstReceipt, $secondResponse->getContent());
        $this->assertSame($localEventId, (int) DB::table('node_event_imports')->where('cloud_event_id', 10)->value('event_id'));
        $this->assertDatabaseCount('node_event_imports', 1);
        $this->assertDatabaseCount('node_operator_audits', 2);
    }

    public function test_failed_import_receipt_rolls_back_the_snapshot_import(): void
    {
        $this->stageSnapshot();
        config(['ingress.authority_private_key' => null]);
        $operator = NodeOperator::factory()->create();
        $this->mock(IssueImportReceipt::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->with(10)
                ->andThrow(ValidationException::withMessages(['transfer' => 'Receipt unavailable.']));
        });

        $this->actingAs($operator, 'node_operator')
            ->from('/node/operator/handoff')
            ->post('/node/operator/handoff/events/10/import', ['confirmation' => 'IMPORT'])
            ->assertRedirect('/node/operator/handoff');

        $this->assertDatabaseCount('node_event_imports', 0);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('node_event_authorities', 0);
        $this->assertDatabaseHas('node_operator_audits', [
            'action' => 'snapshot.imported',
            'outcome' => 'rejected',
        ]);
    }

    public function test_activation_requires_confirmation_and_a_cloud_signed_grant_for_the_exact_import(): void
    {
        $this->stageSnapshot();
        $localEventId = app(ImportReceivedSnapshot::class)->execute(10);
        $import = DB::table('node_event_imports')->where('cloud_event_id', 10)->first();
        $grant = app(AuthorityGrant::class)->sign([
            'protocol' => 1,
            'node_id' => 'node-1',
            'snapshot_id' => $import->snapshot_id,
            'event_id' => 10,
            'version' => 1,
            'sha256' => $import->sha256,
        ]);
        $wrongEventGrant = app(AuthorityGrant::class)->sign([
            'protocol' => 1,
            'node_id' => 'node-1',
            'snapshot_id' => $import->snapshot_id,
            'event_id' => 11,
            'version' => 1,
            'sha256' => $import->sha256,
        ]);
        config(['ingress.authority_private_key' => null]);
        $operator = NodeOperator::factory()->create();
        $this->actingAs($operator, 'node_operator');
        $path = '/node/operator/handoff/events/10/authority';

        $this->from('/node/operator/handoff')->post($path, [
            'grant' => UploadedFile::fake()->createWithContent('authority.txt', $grant),
            'confirmation' => 'WRONG',
        ])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('node_event_authorities', 0);

        [$grantPayload, $grantSignature] = explode('.', $grant, 2);
        $tamperedSignature = ($grantSignature[0] === 'A' ? 'B' : 'A').substr($grantSignature, 1);
        $tamperedGrant = $grantPayload.'.'.$tamperedSignature;
        $this->from('/node/operator/handoff')->post($path, [
            'grant' => UploadedFile::fake()->createWithContent('tampered.txt', $tamperedGrant),
            'confirmation' => 'ACTIVATE',
        ])->assertRedirect('/node/operator/handoff');
        $this->assertDatabaseCount('node_event_authorities', 0);

        $this->from('/node/operator/handoff')->post($path, [
            'grant' => UploadedFile::fake()->createWithContent('wrong-event.txt', $wrongEventGrant),
            'confirmation' => 'ACTIVATE',
        ])->assertRedirect('/node/operator/handoff');
        $this->assertDatabaseCount('node_event_authorities', 0);

        $response = $this->post($path, [
            'grant' => UploadedFile::fake()->createWithContent('authority.txt', $grant),
            'confirmation' => 'ACTIVATE',
        ])->assertOk()
            ->assertSee('Node authority activated')
            ->assertSee('There is no automatic failover or recovery')
            ->assertDontSee($grant);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('node_event_authorities', [
            'cloud_event_id' => 10,
            'event_id' => $localEventId,
            'snapshot_id' => $import->snapshot_id,
            'version' => 1,
            'sha256' => $import->sha256,
            'grant' => $grant,
        ]);
        $this->assertDatabaseHas('node_operator_audits', [
            'actor_id' => $operator->id,
            'action' => 'authority.activated',
            'affected_event_id' => $localEventId,
            'node_id' => 'node-1',
            'outcome' => 'succeeded',
        ]);
        $auditRows = json_encode(DB::table('node_operator_audits')->get()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($grant, $auditRows);

        $this->post($path, [
            'grant' => UploadedFile::fake()->createWithContent('authority.txt', $grant),
            'confirmation' => 'ACTIVATE',
        ])->assertOk()->assertSee('Node authority activated');
        $this->assertDatabaseCount('node_event_authorities', 1);
    }

    public function test_activation_rejects_a_valid_grant_after_a_newer_snapshot_is_staged(): void
    {
        $this->stageSnapshot();
        app(ImportReceivedSnapshot::class)->execute(10);
        $import = DB::table('node_event_imports')->where('cloud_event_id', 10)->first();
        $grant = app(AuthorityGrant::class)->sign([
            'protocol' => 1,
            'node_id' => 'node-1',
            'snapshot_id' => $import->snapshot_id,
            'event_id' => 10,
            'version' => 1,
            'sha256' => $import->sha256,
        ]);

        $newerPayload = $this->payload();
        $newerPayload['version'] = 2;
        $newerJson = json_encode($newerPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        app(ReceiveSnapshot::class)->execute(app(SnapshotEnvelope::class)->seal('node-1', $this->transferKey, $newerJson));

        config(['ingress.authority_private_key' => null]);
        $operator = NodeOperator::factory()->create();
        $this->actingAs($operator, 'node_operator')
            ->from('/node/operator/handoff')
            ->post('/node/operator/handoff/events/10/authority', [
                'grant' => UploadedFile::fake()->createWithContent('authority.txt', $grant),
                'confirmation' => 'ACTIVATE',
            ])
            ->assertRedirect('/node/operator/handoff');

        $this->assertDatabaseCount('node_event_authorities', 0);
        $this->assertDatabaseHas('node_operator_audits', [
            'action' => 'authority.activated',
            'outcome' => 'rejected',
        ]);
    }

    public function test_node_does_not_activate_grants_while_a_cloud_signing_key_is_configured(): void
    {
        $this->stageSnapshot();
        app(ImportReceivedSnapshot::class)->execute(10);
        $import = DB::table('node_event_imports')->where('cloud_event_id', 10)->first();
        $grant = app(AuthorityGrant::class)->sign([
            'protocol' => 1,
            'node_id' => 'node-1',
            'snapshot_id' => $import->snapshot_id,
            'event_id' => 10,
            'version' => 1,
            'sha256' => $import->sha256,
        ]);
        $operator = NodeOperator::factory()->create();

        $this->actingAs($operator, 'node_operator')
            ->post('/node/operator/handoff/events/10/authority', [
                'grant' => UploadedFile::fake()->createWithContent('authority.txt', $grant),
                'confirmation' => 'ACTIVATE',
            ])
            ->assertRedirect('/node/operator/handoff');

        $this->assertDatabaseCount('node_event_authorities', 0);
        $this->assertDatabaseHas('node_operator_audits', [
            'action' => 'authority.activated',
            'outcome' => 'rejected',
        ]);
    }

    /** @return array{0: string, 1: array} */
    private function snapshotPackage(): array
    {
        $payload = $this->payload();
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [app(SnapshotEnvelope::class)->seal('node-1', $this->transferKey, $json), $payload];
    }

    private function stageSnapshot(): void
    {
        [$sealed] = $this->snapshotPackage();
        app(ReceiveSnapshot::class)->execute($sealed);
    }

    private function activateCurrentEvent(): int
    {
        $this->stageSnapshot();
        $eventId = app(ImportReceivedSnapshot::class)->execute(10);
        $import = DB::table('node_event_imports')->where('cloud_event_id', 10)->first();
        $grant = app(AuthorityGrant::class)->sign([
            'protocol' => 1,
            'node_id' => 'node-1',
            'snapshot_id' => $import->snapshot_id,
            'event_id' => 10,
            'version' => 1,
            'sha256' => $import->sha256,
        ]);
        app(ActivateEventAuthority::class)->execute($grant);

        return $eventId;
    }

    private function payload(): array
    {
        $now = now()->utc();

        return [
            'schema_version' => 1,
            'snapshot_id' => (string) Str::uuid(),
            'version' => 1,
            'event' => [
                'id' => 10,
                'title' => 'Handoff event',
                'slug' => 'handoff-event',
                'description' => 'test-payload-private',
                'capacity' => 10,
                'status' => 'published',
                'starts_at' => $now->toISOString(),
                'ends_at' => $now->copy()->addDay()->toISOString(),
                'registration_opens_at' => $now->copy()->subDay()->toISOString(),
                'registration_closes_at' => $now->copy()->addHour()->toISOString(),
                'registration_closed_at' => null,
                'venue' => ['id' => 50, 'name' => 'Venue Hall', 'code' => 'HALL', 'location' => null],
                'organizer' => ['id' => 100, 'name' => 'Event Owner', 'email' => 'owner@example.test', 'role' => 'organizer'],
            ],
            'access_points' => [],
            'staff' => [],
            'registrations' => [],
        ];
    }
}
