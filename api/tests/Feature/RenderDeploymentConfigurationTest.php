<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustConfiguredProxies;
use App\Http\Middleware\TrustRenderCloudHosts;
use App\Support\CloudRuntimeIdentityGuard;
use App\Support\RenderDeploymentConfiguration;
use App\Support\SensitiveMailTransport;
use Illuminate\Http\Request;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RenderDeploymentConfigurationTest extends TestCase
{
    public function test_render_configuration_accepts_verified_direct_postgres_and_database_state(): void
    {
        $certificate = tempnam(sys_get_temp_dir(), 'ingress-ca-');
        $this->assertNotFalse($certificate);
        file_put_contents($certificate, 'test certificate');

        try {
            $this->assertSame([], RenderDeploymentConfiguration::validate($this->validConfiguration($certificate)));
        } finally {
            unlink($certificate);
        }
    }

    public function test_render_cloud_startup_does_not_require_mail_configuration(): void
    {
        $certificate = tempnam(sys_get_temp_dir(), 'ingress-ca-');
        $this->assertNotFalse($certificate);
        file_put_contents($certificate, 'test certificate');
        $config = $this->validConfiguration($certificate);
        $config['mail'] = [];
        unset($config['services']['resend']);

        try {
            $this->assertSame([], RenderDeploymentConfiguration::validate($config));
        } finally {
            unlink($certificate);
        }
    }

    public function test_render_configuration_rejects_a_mismatched_cloud_authority_key_pair(): void
    {
        $certificate = tempnam(sys_get_temp_dir(), 'ingress-ca-');
        $this->assertNotFalse($certificate);
        file_put_contents($certificate, 'test certificate');
        $config = $this->validConfiguration($certificate);
        $otherKeyPair = sodium_crypto_sign_keypair();
        $config['ingress']['authority_public_key'] = base64_encode(sodium_crypto_sign_publickey($otherKeyPair));

        try {
            $errors = RenderDeploymentConfiguration::validate($config);

            $this->assertContains('Cloud authority signing keys must be valid matching Ed25519 keys.', $errors);
        } finally {
            unlink($certificate);
        }
    }

    public function test_render_configuration_rejects_unsafe_settings_without_echoing_secrets(): void
    {
        $certificate = tempnam(sys_get_temp_dir(), 'ingress-ca-');
        $this->assertNotFalse($certificate);
        file_put_contents($certificate, 'test certificate');
        $config = $this->validConfiguration($certificate);
        $databaseUrl = 'postgresql://local-db-password@ep-example-pooler.ap-southeast-1.aws.neon.tech/ingress?sslmode=require';
        $resendKey = 're_test_key_that_must_not_be_printed';
        $config['environment'] = 'testing';
        $config['app']['url'] = 'http://ingress-cloud.onrender.com';
        $config['app']['key'] = 'invalid-key';
        $config['app']['debug'] = true;
        $config['app']['demo_mode'] = true;
        $config['ingress']['mode'] = 'node';
        $config['ingress']['trusted_proxies'] = ['*'];
        $config['database']['connections']['pgsql']['url'] = $databaseUrl;
        $config['services']['resend']['key'] = $resendKey;
        $config['mail']['default'] = 'log';
        $config['session']['driver'] = 'file';
        $config['session']['secure'] = false;
        $config['session']['encrypt'] = false;
        $config['cache']['default'] = 'file';
        $config['queue']['default'] = 'database';
        $config['app']['maintenance']['driver'] = 'file';
        $config['logging']['default'] = 'single';

        try {
            $errors = RenderDeploymentConfiguration::validate($config);
            $messages = implode("\n", $errors);

            $this->assertContains('INGRESS_RUNTIME must be cloud.', $errors);
            $this->assertContains('DB_URL must use the direct Neon endpoint for session advisory locks.', $errors);
            $this->assertContains('DB_URL must resolve to sslmode=verify-full.', $errors);
            $this->assertContains('QUEUE_CONNECTION must be sync on Render Free.', $errors);
            $this->assertStringNotContainsString('local-db-password', $messages);
            $this->assertStringNotContainsString($resendKey, $messages);
            $this->assertStringNotContainsString($databaseUrl, $messages);
        } finally {
            unlink($certificate);
        }
    }

    public function test_only_accepts_postgresql_18(): void
    {
        $this->assertTrue(RenderDeploymentConfiguration::supportsPostgres18(180004));
        $this->assertFalse(RenderDeploymentConfiguration::supportsPostgres18(170006));
        $this->assertFalse(RenderDeploymentConfiguration::supportsPostgres18(190000));
    }

    public function test_laravel_resolves_the_official_resend_mail_transport(): void
    {
        config([
            'mail.default' => 'resend',
            'mail.mailers.resend.transport' => 'resend',
            'services.resend.key' => 're_test_key_for_transport_resolution',
        ]);

        $this->assertInstanceOf(
            ResendTransport::class,
            app('mail.manager')->mailer('resend')->getSymfonyTransport(),
        );
    }

    public function test_sensitive_mail_transport_requires_credentials_valid_sender_and_encrypted_delivery(): void
    {
        config([
            'mail.default' => 'resend',
            'mail.from.address' => 'ingress@verified.example',
            'services.resend.key' => '',
        ]);
        $this->assertFalse(SensitiveMailTransport::canSendSafely());

        config(['services.resend.key' => 're_test_configured_key']);
        $this->assertTrue(SensitiveMailTransport::canSendSafely());

        config(['mail.from.address' => 'not-an-email']);
        $this->assertFalse(SensitiveMailTransport::canSendSafely());
        config(['mail.from.address' => 'ingress@verified.example']);

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.scheme' => 'smtp',
            'mail.mailers.smtp.port' => 587,
        ]);
        $this->assertFalse(SensitiveMailTransport::canSendSafely());

        config(['mail.mailers.smtp.scheme' => 'smtps']);
        $this->assertTrue(SensitiveMailTransport::canSendSafely());

        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']);
        $this->assertFalse(SensitiveMailTransport::canSendSafely());
        config(['mail.default' => 'array']);
        $this->assertFalse(SensitiveMailTransport::canSendSafely());

        config(['mail.default' => 'sendmail']);
        $this->assertFalse(SensitiveMailTransport::canSendSafely());

        config([
            'mail.default' => 'failover',
            'mail.mailers.failover.mailers' => ['resend', 'log'],
            'services.resend.key' => 're_test_configured_key',
        ]);
        $this->assertFalse(SensitiveMailTransport::canSendSafely());
    }

    public function test_cloud_startup_identity_guard_allows_a_new_or_cloud_database(): void
    {
        $connectionName = 'render_identity_guard';
        config(['database.connections.'.$connectionName => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge($connectionName);
        $connection = DB::connection($connectionName);

        try {
            $guard = new CloudRuntimeIdentityGuard;
            $this->assertNull($guard->assertUnboundOrCloud($connection));

            $connection->getSchemaBuilder()->create('runtime_identity', function ($table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->string('mode');
                $table->string('identity');
            });
            $this->assertNull($guard->assertUnboundOrCloud($connection));

            $connection->table('runtime_identity')->insert(['id' => 1, 'mode' => 'cloud', 'identity' => 'cloud']);
            $this->assertNull($guard->assertUnboundOrCloud($connection));
        } finally {
            DB::disconnect($connectionName);
            DB::purge($connectionName);
        }
    }

    public function test_cloud_startup_identity_guard_rejects_an_event_node_before_migrations(): void
    {
        $connectionName = 'render_identity_guard';
        config(['database.connections.'.$connectionName => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge($connectionName);
        $connection = DB::connection($connectionName);
        $connection->getSchemaBuilder()->create('runtime_identity', function ($table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('mode');
            $table->string('identity');
        });
        $connection->table('runtime_identity')->insert(['id' => 1, 'mode' => 'node', 'identity' => 'node-1']);

        try {
            $this->expectException(RuntimeException::class);
            (new CloudRuntimeIdentityGuard)->assertUnboundOrCloud($connection);
        } finally {
            DB::disconnect($connectionName);
            DB::purge($connectionName);
        }
    }

    public function test_cloud_operator_identity_guard_rejects_an_event_node_database(): void
    {
        $connectionName = 'render_operator_identity_guard';
        config(['database.connections.'.$connectionName => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge($connectionName);
        $connection = DB::connection($connectionName);
        $connection->getSchemaBuilder()->create('runtime_identity', function ($table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('mode');
            $table->string('identity');
        });
        $connection->table('runtime_identity')->insert(['id' => 1, 'mode' => 'node', 'identity' => 'node-1']);

        try {
            $this->expectException(RuntimeException::class);
            (new CloudRuntimeIdentityGuard)->assertCloud($connection);
        } finally {
            DB::disconnect($connectionName);
            DB::purge($connectionName);
        }
    }

    public function test_cloud_operator_identity_guard_requires_a_bound_cloud_database(): void
    {
        $connectionName = 'render_operator_identity_guard';
        config(['database.connections.'.$connectionName => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge($connectionName);
        $connection = DB::connection($connectionName);
        $connection->getSchemaBuilder()->create('runtime_identity', function ($table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('mode');
            $table->string('identity');
        });
        $connection->table('runtime_identity')->insert(['id' => 1, 'mode' => 'cloud', 'identity' => 'cloud']);

        try {
            $this->assertNull((new CloudRuntimeIdentityGuard)->assertCloud($connection));
        } finally {
            DB::disconnect($connectionName);
            DB::purge($connectionName);
        }
    }

    public function test_render_proxy_trust_uses_only_forwarded_ip_and_scheme(): void
    {
        Request::setTrustedHosts([]);
        config(['ingress.trusted_proxies' => ['REMOTE_ADDR']]);

        $request = Request::create('http://ingress-cloud.onrender.com/up');
        $request->server->set('REMOTE_ADDR', '203.0.113.10');
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->headers->set('X-Forwarded-Host', 'attacker.example');

        try {
            (new TrustConfiguredProxies)->handle($request, static fn (): Response => new Response);

            $this->assertTrue($request->isSecure());
            $this->assertSame('ingress-cloud.onrender.com', $request->getHost());
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }

    public function test_an_untrusted_client_cannot_set_the_request_scheme(): void
    {
        Request::setTrustedHosts([]);
        config(['ingress.trusted_proxies' => ['203.0.113.10']]);

        $request = Request::create('http://ingress-cloud.onrender.com/up');
        $request->server->set('REMOTE_ADDR', '198.51.100.22');
        $request->headers->set('X-Forwarded-Proto', 'https');

        try {
            (new TrustConfiguredProxies)->handle($request, static fn (): Response => new Response);

            $this->assertFalse($request->isSecure());
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }

    public function test_production_cloud_trusts_only_the_app_and_configured_hosts(): void
    {
        Request::setTrustedHosts([]);
        $originalEnvironment = app()->environment();
        $originalAppUrl = config('app.url');
        $originalRuntime = config('ingress.mode');
        $originalTrustedHosts = config('ingress.trusted_hosts');

        app()->detectEnvironment(static fn (): string => 'production');
        config([
            'app.url' => 'https://ingress-cloud.onrender.com',
            'ingress.mode' => 'cloud',
            'ingress.trusted_hosts' => ['admin.example.edu'],
        ]);

        try {
            $hosts = TrustRenderCloudHosts::patterns();

            $this->assertSame([
                '^ingress\\-cloud\\.onrender\\.com$',
                '^admin\\.example\\.edu$',
            ], $hosts);

            $allowed = Request::create('https://ingress-cloud.onrender.com/up');
            $allowedResponse = (new TrustRenderCloudHosts)->handle(
                $allowed,
                static fn (Request $request): Response => new Response($request->getHost()),
            );
            $this->assertSame('ingress-cloud.onrender.com', $allowedResponse->getContent());

            $rejected = false;
            try {
                (new TrustRenderCloudHosts)->handle(
                    Request::create('https://attacker.example/up'),
                    static fn (Request $request): Response => new Response($request->getHost()),
                );
            } catch (SuspiciousOperationException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);

            config(['ingress.mode' => 'node']);
            $this->assertSame([], TrustRenderCloudHosts::patterns());
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
            config([
                'app.url' => $originalAppUrl,
                'ingress.mode' => $originalRuntime,
                'ingress.trusted_hosts' => $originalTrustedHosts,
            ]);
            Request::setTrustedHosts([]);
        }
    }

    /** @return array<string, mixed> */
    private function validConfiguration(string $certificate): array
    {
        $keyPair = sodium_crypto_sign_keypair();

        return [
            'environment' => 'production',
            'app' => [
                'url' => 'https://ingress-cloud.onrender.com',
                'key' => 'base64:'.base64_encode(str_repeat('k', 32)),
                'debug' => false,
                'demo_mode' => false,
                'maintenance' => ['driver' => 'cache', 'store' => 'database'],
            ],
            'ingress' => [
                'mode' => 'cloud',
                'trusted_proxies' => ['REMOTE_ADDR'],
                'authority_private_key' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
                'authority_public_key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
            ],
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => [
                        'url' => 'postgresql://role:password@ep-example.ap-southeast-1.aws.neon.tech/ingress?sslmode=verify-full',
                        'sslmode' => 'verify-full',
                        'sslrootcert' => $certificate,
                    ],
                ],
            ],
            'session' => ['driver' => 'database', 'secure' => true, 'encrypt' => true],
            'cache' => ['default' => 'database'],
            'queue' => ['default' => 'sync'],
            'mail' => [
                'default' => 'resend',
                'from' => ['address' => 'ingress@verified.example'],
            ],
            'services' => ['resend' => ['key' => 're_test_key']],
            'logging' => ['default' => 'stderr'],
        ];
    }
}
