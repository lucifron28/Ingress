<?php

namespace Tests\Feature;

use App\Support\NodeOperatorOperationsStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeOperatorOperationsStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_rejects_app_urls_with_forbidden_components(): void
    {
        $this->configureValidNode();
        $status = app(NodeOperatorOperationsStatus::class);

        $this->assertTrue($status->dashboard()['httpsConfigured']);
        $this->assertTrue($status->dashboard()['syncConfigured']);

        foreach ([
            'https://user@node-1.example.edu',
            'https://user:password@node-1.example.edu',
            'https://node-1.example.edu?debug=1',
            'https://node-1.example.edu#fragment',
        ] as $url) {
            config(['app.url' => $url]);
            $dashboard = $status->dashboard();

            $this->assertFalse($dashboard['httpsConfigured'], $url);
            $this->assertFalse($dashboard['syncConfigured'], $url);
        }
    }

    public function test_dashboard_rejects_cloud_sync_urls_with_forbidden_components(): void
    {
        $this->configureValidNode();
        $status = app(NodeOperatorOperationsStatus::class);

        $this->assertTrue($status->dashboard()['syncConfigured']);

        foreach ([
            'https://user@ingress-pp2u.onrender.com/api/v1',
            'https://user:password@ingress-pp2u.onrender.com/api/v1',
            'https://ingress-pp2u.onrender.com/api/v1?debug=1',
            'https://ingress-pp2u.onrender.com/api/v1#fragment',
        ] as $url) {
            config(['ingress.cloud_sync_url' => $url]);

            $this->assertFalse($status->dashboard()['syncConfigured'], $url);
        }
    }

    private function configureValidNode(): void
    {
        config([
            'ingress.mode' => 'node',
            'ingress.node_id' => 'node-1',
            'app.url' => 'https://node-1.example.edu',
            'ingress.cloud_sync_url' => 'https://ingress-pp2u.onrender.com/api/v1',
            'ingress.node_sync_key' => base64_encode(str_repeat('a', 32)),
        ]);
    }
}
