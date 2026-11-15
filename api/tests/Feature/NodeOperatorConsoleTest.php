<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRuntimeDatabase;
use App\Models\NodeOperator;
use App\Models\NodeOperatorAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NodeOperatorConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_demo_operator_bootstrap_is_loopback_only_and_cannot_replace_an_operator(): void
    {
        $this->configureNodeOperatorConsole();
        config([
            'ingress.lan_demo' => true,
            'ingress.node_bind_address' => '192.168.1.20',
            'app.url' => 'http://192.168.1.20:8000',
            'session.secure' => false,
            'ingress.operator_allowed_cidrs' => ['127.0.0.1/32', '192.168.1.0/24'],
        ]);
        DB::table('runtime_identity')->insert(['id' => 1, 'mode' => 'node', 'identity' => 'node-1']);
        $this->get('/node/operator/setup')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.5'])
            ->get('/node/operator/setup')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.5'])
            ->post('/node/operator/setup', [
                'name' => 'Remote', 'email' => 'remote@example.test',
                'password' => 'a-long-demo-password', 'password_confirmation' => 'a-long-demo-password',
            ])->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->post('/node/operator/setup', [
                'name' => 'Local operator',
                'email' => 'operator@example.test',
                'password' => 'a-long-demo-password',
                'password_confirmation' => 'a-long-demo-password',
            ])->assertRedirect('/node/operator/login');
        $this->assertDatabaseCount('node_operators', 1);
        $this->get('/node/operator/setup')->assertNotFound();
        $this->post('/node/operator/setup', [
            'name' => 'Another operator', 'email' => 'other@example.test',
            'password' => 'another-demo-password', 'password_confirmation' => 'another-demo-password',
        ])->assertStatus(409);
        $this->assertDatabaseCount('node_operators', 1);
    }

    public function test_local_demo_bootstrap_accepts_the_exact_docker_operator_gateway(): void
    {
        $this->configureNodeOperatorConsole();
        config([
            'ingress.lan_demo' => true,
            'ingress.node_bind_address' => '192.168.1.20',
            'app.url' => 'http://192.168.1.20:8000',
            'session.secure' => false,
            'ingress.operator_gateway' => '172.29.241.1',
            'ingress.operator_allowed_cidrs' => ['172.29.241.1/32'],
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '172.29.241.1'])
            ->get('/node/operator/setup')->assertOk();
    }

    public function test_login_page_is_node_only_and_private(): void
    {
        $this->configureNodeOperatorConsole();

        $response = $this->withHeader('Host', 'attacker.example')->get('/node/operator/login')
            ->assertOk()
            ->assertSee('Event Node Operator')
            ->assertSee('action="/node/operator/login"', false)
            ->assertDontSee('attacker.example', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $sessionCookie = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $sessionCookie = $cookie;
                break;
            }
        }

        $this->assertNotNull($sessionCookie);
        $this->assertTrue($sessionCookie->isSecure());
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertSame('strict', $sessionCookie->getSameSite());
        $this->get('/')->assertRedirect('/node/operator/login');
    }

    public function test_operator_console_does_not_trust_forwarded_client_addresses(): void
    {
        $this->configureNodeOperatorConsole();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.15'])
            ->withHeader('X-Forwarded-For', '127.0.0.1')
            ->get('/node/operator/login')
            ->assertForbidden();
    }

    public function test_operator_login_is_unavailable_on_cloud_runtime(): void
    {
        $this->configureNodeOperatorConsole('cloud');

        $this->get('/node/operator/login')->assertStatus(503);
    }

    public function test_operator_login_uses_its_own_guard_and_rotates_the_session(): void
    {
        $this->configureNodeOperatorConsole();
        $operator = $this->createOperator();

        $this->get('/node/operator/login')->assertOk();
        $before = session()->getId();

        $this->withHeader('Host', 'attacker.example')->post('/node/operator/login', [
            'email' => $operator->email,
            'password' => 'a-long-demo-password',
        ])->assertRedirect('/node/operator');

        $this->assertAuthenticatedAs($operator, 'node_operator');
        $this->assertNotSame($before, session()->getId());
        $this->assertGuest('web');
        $this->assertDatabaseHas('node_operator_audits', [
            'actor_id' => $operator->id,
            'action' => 'login.succeeded',
        ]);
    }

    public function test_invalid_and_disabled_operator_credentials_have_the_same_public_error(): void
    {
        $this->configureNodeOperatorConsole();
        $disabled = $this->createOperator(['is_active' => false]);

        $invalid = $this->from('/node/operator/login')->post('/node/operator/login', [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
        ]);
        $invalid->assertRedirect('/node/operator/login')->assertSessionHasErrors('email');
        $firstError = session('errors')->getBag('default')->first('email');

        $inactive = $this->from('/node/operator/login')->post('/node/operator/login', [
            'email' => $disabled->email,
            'password' => 'a-long-demo-password',
        ]);
        $inactive->assertRedirect('/node/operator/login')->assertSessionHasErrors('email');
        $secondError = session('errors')->getBag('default')->first('email');

        $this->assertSame($firstError, $secondError);
        $this->assertGuest('node_operator');
        $this->assertDatabaseCount('node_operator_audits', 0);
    }

    public function test_malformed_credentials_return_to_the_fixed_login_path_without_flashing_password(): void
    {
        $this->configureNodeOperatorConsole();

        $this->withHeader('Host', 'attacker.example')
            ->post('/node/operator/login', [
                'email' => 'not-an-email',
                'password' => 'never-flash-this',
            ])
            ->assertRedirect('/node/operator/login')
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_dashboard_requires_an_active_node_operator_and_never_caches_the_page(): void
    {
        $this->configureNodeOperatorConsole();

        $this->get('/node/operator')->assertRedirect('/node/operator/login');

        $operator = $this->createOperator();
        $response = $this->actingAs($operator, 'node_operator')
            ->get('/node/operator')
            ->assertOk()
            ->assertSee('Operator console')
            ->assertDontSee('fonts.googleapis.com');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_sync_action_requires_operator_authentication_and_is_rate_limited(): void
    {
        $this->configureNodeOperatorConsole();
        $this->post('/node/operator/events/1/sync')->assertRedirect('/node/operator/login');
        $operator = $this->createOperator();

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->actingAs($operator, 'node_operator')
                ->post('/node/operator/events/1/sync')
                ->assertNotFound();
        }

        $this->actingAs($operator, 'node_operator')
            ->post('/node/operator/events/1/sync')
            ->assertStatus(429);
    }

    public function test_inactive_operator_is_not_authorized_by_an_existing_session(): void
    {
        $this->configureNodeOperatorConsole();
        $operator = $this->createOperator(['is_active' => false]);

        $this->actingAs($operator, 'node_operator')
            ->get('/node/operator')
            ->assertRedirect('/node/operator/login');
    }

    public function test_logout_invalidates_the_operator_session_and_records_only_safe_audit_fields(): void
    {
        $this->configureNodeOperatorConsole();
        $operator = $this->createOperator();

        $this->actingAs($operator, 'node_operator')
            ->post('/node/operator/logout')
            ->assertRedirect('/node/operator/login');

        $this->assertGuest('node_operator');
        $this->assertDatabaseHas('node_operator_audits', [
            'actor_id' => $operator->id,
            'action' => 'logout.succeeded',
        ]);
        $this->assertSame(
            ['id', 'actor_id', 'action', 'created_at', 'affected_user_id', 'affected_event_id', 'node_id', 'outcome'],
            Schema::getColumnListing('node_operator_audits'),
        );
    }

    public function test_operator_console_fails_closed_without_secure_database_backends(): void
    {
        $this->configureNodeOperatorConsole();

        config(['session.secure' => false]);
        $this->get('/node/operator/login')->assertStatus(503);

        config(['session.secure' => true, 'session.encrypt' => false]);
        $this->get('/node/operator/login')->assertStatus(503);

        config(['session.encrypt' => true, 'session.http_only' => false]);
        $this->get('/node/operator/login')->assertStatus(503);

        config(['session.http_only' => true, 'session.same_site' => 'lax']);
        $this->get('/node/operator/login')->assertStatus(503);

        config(['session.same_site' => 'strict', 'session.driver' => 'file']);
        $this->get('/node/operator/login')->assertStatus(503);

        config(['session.driver' => 'database', 'cache.default' => 'array']);
        $this->get('/node/operator/login')->assertStatus(503);
    }

    public function test_operator_audit_records_cannot_be_updated_or_deleted(): void
    {
        $this->configureNodeOperatorConsole();
        $operator = $this->createOperator();
        $audit = NodeOperatorAudit::query()->create([
            'actor_id' => $operator->id,
            'action' => 'login.succeeded',
            'created_at' => now(),
        ]);

        try {
            $audit->update(['action' => 'password.changed']);
            $this->fail('Audit updates must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame('Node operator audit records cannot be changed.', $exception->getMessage());
        }

        try {
            $audit->delete();
            $this->fail('Audit deletion must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertSame('Node operator audit records cannot be deleted.', $exception->getMessage());
        }
    }

    public function test_production_operator_console_requires_https(): void
    {
        $this->configureNodeOperatorConsole();
        $this->withoutMiddleware(EnsureRuntimeDatabase::class);
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('/node/operator/login')->assertForbidden();
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->get('/node/operator/login')
            ->assertForbidden();
        $this->get('https://localhost/node/operator/login')
            ->assertOk();
    }

    public function test_production_operator_console_allows_http_only_for_the_configured_private_lan_demo(): void
    {
        $this->configureNodeOperatorConsole();
        $this->withoutMiddleware(EnsureRuntimeDatabase::class);
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('/node/operator/login')->assertForbidden();

        config([
            'ingress.lan_demo' => true,
            'ingress.node_bind_address' => '192.168.1.20',
            'app.url' => 'http://192.168.1.20:8000',
            'session.secure' => false,
        ]);
        $response = $this->get('/node/operator/login')->assertOk();
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertFalse($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());

        config([
            'ingress.mode' => 'cloud',
            'app.url' => 'https://localhost',
            'session.secure' => true,
        ]);
        $this->get('/node/operator/login')->assertStatus(503);
    }

    public function test_malformed_operator_allowlist_fails_closed(): void
    {
        $this->configureNodeOperatorConsole();
        config(['ingress.operator_allowed_cidrs' => ['not-a-cidr']]);

        $this->get('/node/operator/login')->assertStatus(503);
    }

    public function test_operator_login_throttle_uses_a_readable_private_form_response(): void
    {
        $this->configureNodeOperatorConsole();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/node/operator/login', [
                'email' => 'missing@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/node/operator/login', [
            'email' => 'missing@example.test',
            'password' => 'wrong-password',
        ])->assertRedirect('/node/operator/login');

        $response = $this->get('/node/operator/login')
            ->assertSee('Too many login attempts. Please try again in a minute.')
            ->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_bootstrap_command_creates_an_operator_only_on_a_bound_node_database(): void
    {
        config(['ingress.mode' => 'cloud', 'ingress.node_id' => 'node-1']);
        $this->artisan('ingress:node-operator:create')
            ->expectsOutputToContain('Operator accounts can only be created on an Event Node.')
            ->assertExitCode(1);
        $this->assertDatabaseCount('node_operators', 0);

        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1']);
        $this->artisan('ingress:node-operator:create')
            ->expectsQuestion('Operator name', 'Venue Operator')
            ->expectsQuestion('Operator email', 'OPERATOR@example.test')
            ->expectsQuestion('Password, at least 12 characters', 'operator-demo-password')
            ->expectsQuestion('Confirm password', 'operator-demo-password')
            ->expectsOutputToContain('Event Node operator account created.')
            ->assertExitCode(0);

        $operator = NodeOperator::query()->firstOrFail();
        $this->assertSame('operator@example.test', $operator->email);
        $this->assertTrue(Hash::check('operator-demo-password', $operator->password));
        $this->assertDatabaseHas('runtime_identity', [
            'mode' => 'node',
            'identity' => 'node-1',
        ]);

        $this->artisan('ingress:node-operator:create')
            ->expectsOutputToContain('An Event Node operator is already provisioned.')
            ->assertExitCode(1);
        $this->assertDatabaseCount('node_operators', 1);
    }

    public function test_bootstrap_command_refuses_a_database_bound_to_another_runtime(): void
    {
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1']);
        DB::table('runtime_identity')->insert([
            'id' => 1,
            'mode' => 'cloud',
            'identity' => 'cloud',
        ]);

        $this->artisan('ingress:node-operator:create')
            ->expectsOutputToContain('Could not verify this Event Node database.')
            ->assertExitCode(1);
        $this->assertDatabaseCount('node_operators', 0);
    }

    private function configureNodeOperatorConsole(string $runtime = 'node'): void
    {
        config([
            'ingress.mode' => $runtime,
            'ingress.node_id' => 'node-1',
            'ingress.operator_allowed_cidrs' => ['127.0.0.1/32', '::1/128'],
            'session.driver' => 'database',
            'session.secure' => true,
            'session.http_only' => true,
            'session.encrypt' => true,
            'session.same_site' => 'strict',
            'cache.default' => 'database',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createOperator(array $attributes = []): NodeOperator
    {
        return NodeOperator::factory()->create(array_merge([
            'name' => 'Venue Operator',
            'email' => 'operator@example.test',
            'password' => 'a-long-demo-password',
            'is_active' => true,
        ], $attributes));
    }
}
