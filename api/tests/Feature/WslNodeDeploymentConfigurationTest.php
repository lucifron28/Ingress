<?php

namespace Tests\Feature;

use Tests\TestCase;

class WslNodeDeploymentConfigurationTest extends TestCase
{
    public function test_nginx_serves_the_event_node_on_tls_and_rejects_unknown_hosts(): void
    {
        $configuration = file_get_contents(base_path('deploy/wsl/nginx/ingress-node.conf'));

        $this->assertIsString($configuration);
        $this->assertStringContainsString('listen 443 ssl default_server;', $configuration);
        $this->assertStringContainsString('ssl_reject_handshake on;', $configuration);
        $this->assertStringContainsString('server_name node-1.example.edu;', $configuration);
        $this->assertStringContainsString('return 444;', $configuration);
        $this->assertStringNotContainsString('listen 80', $configuration);
    }

    public function test_nginx_keeps_laravel_and_fastcgi_behind_the_https_vhost(): void
    {
        $configuration = file_get_contents(base_path('deploy/wsl/nginx/ingress-node.conf'));

        $this->assertIsString($configuration);
        $this->assertStringContainsString('root /srv/ingress/api/public;', $configuration);
        $this->assertStringContainsString('ssl_protocols TLSv1.2 TLSv1.3;', $configuration);
        $this->assertStringContainsString('client_max_body_size 32m;', $configuration);
        $this->assertStringContainsString('location = /index.php {', $configuration);
        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php8.3-fpm.sock;', $configuration);
        $this->assertStringContainsString('try_files $uri $uri/ /index.php?$query_string;', $configuration);
        $this->assertStringContainsString('fastcgi_param HTTP_PROXY "";', $configuration);
        $this->assertStringNotContainsString('proxy_set_header', $configuration);
        $this->assertStringNotContainsString('X-Forwarded-', $configuration);
    }

    public function test_nginx_denies_direct_access_to_application_secrets_and_private_paths(): void
    {
        $configuration = file_get_contents(base_path('deploy/wsl/nginx/ingress-node.conf'));

        $this->assertIsString($configuration);
        $this->assertStringContainsString('(?:\\.env|\\.git|vendor|storage|bootstrap/cache)', $configuration);
        $this->assertStringContainsString('deny all;', $configuration);
    }
}
