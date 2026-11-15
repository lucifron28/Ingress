<?php

namespace Tests\Unit;

use App\Support\NodeDockerDeploymentConfiguration;
use PHPUnit\Framework\TestCase;

class NodeDockerDeploymentConfigurationTest extends TestCase
{
    public function test_valid_production_node_configuration_is_accepted(): void
    {
        $this->assertSame([], NodeDockerDeploymentConfiguration::validate($this->validConfiguration()));
    }

    public function test_unsafe_runtime_and_persistence_settings_are_rejected_without_echoing_secrets(): void
    {
        $configuration = $this->validConfiguration();
        $databasePassword = 'docker_node_database_password_for_test_only';
        $syncKey = 'super-secret-invalid-node-key';
        $databaseUrl = 'postgresql://cloud-password@example.neon.tech/cloud?sslmode=require';
        $authorityPrivateKey = base64_encode(str_repeat('p', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES));
        $configuration['environment'] = 'testing';
        $configuration['app']['url'] = 'http://user:password@node.example.edu';
        $configuration['app']['key'] = 'invalid-laravel-key';
        $configuration['app']['debug'] = true;
        $configuration['ingress']['mode'] = 'cloud';
        $configuration['ingress']['node_id'] = '../cloud';
        $configuration['ingress']['node_sync_key'] = $syncKey;
        $configuration['ingress']['authority_private_key'] = $authorityPrivateKey;
        $configuration['ingress']['trusted_proxies'] = ['*'];
        $configuration['ingress']['operator_allowed_cidrs'] = ['0.0.0.0/0'];
        $configuration['database']['default'] = 'sqlite';
        $configuration['database']['connections']['pgsql']['url'] = $databaseUrl;
        $configuration['database']['connections']['pgsql']['password'] = $databasePassword;
        $configuration['session']['driver'] = 'file';
        $configuration['session']['secure'] = false;
        $configuration['session']['encrypt'] = false;
        $configuration['session']['same_site'] = 'lax';
        $configuration['cache']['default'] = 'file';
        $configuration['logging']['default'] = 'single';

        $errors = NodeDockerDeploymentConfiguration::validate($configuration);
        $messages = implode("\n", $errors);

        $this->assertContains('APP_ENV must be production.', $errors);
        $this->assertContains('INGRESS_RUNTIME must be node.', $errors);
        $this->assertContains('APP_DEBUG must be false.', $errors);
        $this->assertContains('APP_URL must be an HTTPS node origin.', $errors);
        $this->assertContains('APP_KEY must be a valid 32-byte Laravel key.', $errors);
        $this->assertContains('INGRESS_NODE_ID must be a valid node ID.', $errors);
        $this->assertContains('INGRESS_NODE_SYNC_KEY must be a 32-byte base64 key.', $errors);
        $this->assertContains('INGRESS_AUTHORITY_PRIVATE_KEY must not be configured on an Event Node.', $errors);
        $this->assertContains('TRUSTED_PROXIES must be empty for the direct HTTPS node listener.', $errors);
        $this->assertContains('Operator access must allow only the configured Docker bridge gateway.', $errors);
        $this->assertContains('DB_CONNECTION must use the private ingress-db PostgreSQL service and credentials.', $errors);
        $this->assertContains('DB_URL must be empty so the node uses only its private PostgreSQL service.', $errors);
        $this->assertContains('Sessions must use encrypted PostgreSQL storage and secure cookies.', $errors);
        $this->assertContains('CACHE_STORE must use the database cache store.', $errors);
        $this->assertContains('LOG_CHANNEL must write to stderr.', $errors);
        $this->assertStringNotContainsString($databasePassword, $messages);
        $this->assertStringNotContainsString($syncKey, $messages);
        $this->assertStringNotContainsString($authorityPrivateKey, $messages);
        $this->assertStringNotContainsString($databaseUrl, $messages);
        $this->assertStringNotContainsString('cloud-password', $messages);
        $this->assertStringNotContainsString('user:password', $messages);
    }

    public function test_app_url_host_must_be_safe_for_nginx_server_name_configuration(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['app']['url'] = 'https://node.example.edu;return 444;#';

        $this->assertContains(
            'APP_URL must be an HTTPS node origin.',
            NodeDockerDeploymentConfiguration::validate($configuration),
        );
    }

    public function test_lan_demo_accepts_http_only_for_the_selected_private_node_address(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['ingress']['lan_demo'] = true;
        $configuration['ingress']['node_bind_address'] = '192.168.55.158';
        $configuration['app']['url'] = 'http://192.168.55.158:8000';
        $configuration['session']['secure'] = false;

        $this->assertSame([], NodeDockerDeploymentConfiguration::validate($configuration));
    }

    public function test_http_node_url_requires_explicit_lan_demo_and_matching_private_bind_address(): void
    {
        $configuration = $this->validConfiguration();
        $configuration['ingress']['node_bind_address'] = '192.168.55.158';
        $configuration['app']['url'] = 'http://192.168.55.158:8000';

        $this->assertContains(
            'APP_URL must be an HTTPS node origin.',
            NodeDockerDeploymentConfiguration::validate($configuration),
        );

        $configuration['ingress']['lan_demo'] = true;
        $configuration['app']['url'] = 'http://8.8.8.8:8000';
        $errors = NodeDockerDeploymentConfiguration::validate($configuration);

        $this->assertContains(
            'APP_URL must be an HTTP origin matching the selected private LAN address.',
            $errors,
        );
    }

    /** @return array<string, mixed> */
    private function validConfiguration(): array
    {
        return [
            'environment' => 'production',
            'app' => [
                'url' => 'https://node.example.edu',
                'key' => 'base64:'.base64_encode(str_repeat('k', 32)),
                'debug' => false,
                'demo_mode' => false,
                'maintenance' => ['driver' => 'cache', 'store' => 'database'],
            ],
            'ingress' => [
                'mode' => 'node',
                'lan_demo' => false,
                'node_bind_address' => '192.168.1.50',
                'node_id' => 'venue-node-1',
                'node_sync_key' => base64_encode(str_repeat('s', 32)),
                'cloud_sync_url' => 'https://ingress-pp2u.onrender.com/api/v1',
                'authority_public_key' => base64_encode(str_repeat('p', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
                'authority_private_key' => '',
                'trusted_proxies' => [],
                'operator_gateway' => '172.29.241.1',
                'operator_allowed_cidrs' => ['172.29.241.1/32'],
            ],
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => [
                        'driver' => 'pgsql',
                        'host' => 'ingress-db',
                        'database' => 'ingress_node',
                        'username' => 'ingress_node',
                        'password' => 'docker_node_database_password_for_test_only',
                    ],
                ],
            ],
            'session' => [
                'driver' => 'database',
                'secure' => true,
                'encrypt' => true,
                'http_only' => true,
                'same_site' => 'strict',
            ],
            'cache' => ['default' => 'database'],
            'queue' => ['default' => 'sync'],
            'logging' => ['default' => 'stderr'],
        ];
    }
}
