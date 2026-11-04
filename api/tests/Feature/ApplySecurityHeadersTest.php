<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplySecurityHeadersTest extends TestCase
{
    public function test_application_responses_include_security_headers(): void
    {
        $response = $this->get('/up');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_only_for_secure_production_requests(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_trusted_proxy_configuration_controls_https_detection(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['ingress.trusted_proxies' => ['127.0.0.1']]);

        $this->get('/up', ['X-Forwarded-Proto' => 'https'])
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
