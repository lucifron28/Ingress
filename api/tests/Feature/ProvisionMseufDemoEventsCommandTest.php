<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\VenueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProvisionMseufDemoEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $organizer;

    private int $venueCount;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'local',
            'ingress.mode' => 'cloud',
            'ingress.node_id' => null,
            'ingress.render_environment' => false,
            'ingress.render_service_id' => null,
            'ingress.mseuf_demo_events.shared_provisioning_enabled' => false,
            'database.default' => 'sqlite',
            'database.connections.sqlite.host' => null,
        ]);

        $this->organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->seed(VenueSeeder::class);
        $this->venueCount = Venue::count();
    }

    public function test_shared_neon_provisioning_requires_an_explicit_target_and_opt_in(): void
    {
        $this->artisan('ingress:provision-mseuf-demo-events')
            ->expectsOutputToContain('--target=shared-neon')
            ->assertExitCode(1);

        $this->artisan('ingress:provision-mseuf-demo-events', [
            '--target' => 'shared-neon',
            '--organizer-id' => (string) $this->organizer->id,
        ])
            ->expectsOutputToContain('INGRESS_MSEUF_SHARED_DEMO_EVENTS_ENABLED')
            ->assertExitCode(1);

        $this->assertSame(0, Event::count());
    }

    public function test_shared_neon_provisioning_refuses_local_database_render_and_event_node_targets(): void
    {
        config(['ingress.mseuf_demo_events.shared_provisioning_enabled' => true]);
        $arguments = [
            '--target' => 'shared-neon',
            '--organizer-id' => (string) $this->organizer->id,
        ];

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.driver' => 'pgsql',
            'database.connections.pgsql.url' => 'postgresql://demo:demo@ep-example.ap-southeast-1.aws.neon.tech/ingress?sslmode=disable',
        ]);

        $this->artisan('ingress:provision-mseuf-demo-events', $arguments)
            ->expectsOutputToContain('using TLS')
            ->assertExitCode(1);

        config(['app.env' => 'production']);

        $this->artisan('ingress:provision-mseuf-demo-events', $arguments)
            ->expectsOutputToContain('local Cloud')
            ->assertExitCode(1);

        config([
            'app.env' => 'local',
            'ingress.render_environment' => true,
        ]);

        $this->artisan('ingress:provision-mseuf-demo-events', $arguments)
            ->expectsOutputToContain('local Cloud')
            ->assertExitCode(1);

        config([
            'ingress.render_environment' => false,
            'ingress.node_id' => 'venue-node-1',
        ]);

        $this->artisan('ingress:provision-mseuf-demo-events', $arguments)
            ->expectsOutputToContain('local Cloud')
            ->assertExitCode(1);

        config(['database.default' => 'sqlite']);
        DB::setDefaultConnection('sqlite');
        $this->assertSame(0, Event::count());
        $this->assertSame($this->venueCount, Venue::count());
    }
}
