<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRuntimeDatabase;
use Tests\TestCase;

class CloudCsrfProtectionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalConfig = [];

    private ?string $originalEnvironment = null;

    public function test_cloud_browser_mutations_reject_missing_csrf_tokens_before_route_actions(): void
    {
        $this->enableProductionCloudRequestChecks();

        $this->post('https://ingress.example.test/events/missing-event/register')
            ->assertStatus(419);

        $this->post('https://ingress.example.test/checkout/missing-order/process')
            ->assertStatus(419);

        $this->post('https://ingress.example.test/organizer/events/1/simulated-payments')
            ->assertStatus(419);
    }

    public function test_cloud_browser_mutation_accepts_a_matching_session_csrf_token(): void
    {
        $this->enableProductionCloudRequestChecks();
        $token = str_repeat('a', 40);

        $this->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->post('https://ingress.example.test/logout')
            ->assertRedirect('https://ingress.example.test/login');
    }

    protected function tearDown(): void
    {
        if ($this->originalEnvironment !== null) {
            $this->app->detectEnvironment(fn (): string => $this->originalEnvironment);
            config($this->originalConfig);
        }

        parent::tearDown();
    }

    private function enableProductionCloudRequestChecks(): void
    {
        $this->originalEnvironment ??= $this->app->environment();
        $this->originalConfig = [
            'app.url' => config('app.url'),
            'app.key' => config('app.key'),
            'ingress.mode' => config('ingress.mode'),
            'session.driver' => config('session.driver'),
        ];
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->withoutMiddleware(EnsureRuntimeDatabase::class);

        config([
            'app.url' => 'https://ingress.example.test',
            'app.key' => 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
            'ingress.mode' => 'cloud',
            'session.driver' => 'array',
        ]);
    }
}
