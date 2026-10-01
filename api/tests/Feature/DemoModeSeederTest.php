<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Providers\AppServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoModeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_accounts_are_not_seeded_when_demo_mode_is_off(): void
    {
        config(['app.demo_mode' => false]);

        $this->seed(DatabaseSeeder::class);

        // No predictable credential may be created.
        $this->assertEquals(0, User::count(), 'demo mode off must not create any user');

        // Venues are harmless reference data and are still seeded.
        $this->assertGreaterThan(0, Venue::count());
        $this->assertEquals(0, Event::count());
    }

    public function test_demo_accounts_are_seeded_when_demo_mode_is_on(): void
    {
        config(['app.demo_mode' => true]);

        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, User::count());
        $this->assertDatabaseHas('users', [
            'email' => 'organizer@mseuf.edu.ph',
            'role' => User::ROLE_ORGANIZER,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'staff.gate_a@mseuf.edu.ph',
            'role' => User::ROLE_STAFF,
        ]);
        $this->assertGreaterThan(0, Event::count());
    }

    public function test_demo_mode_is_rejected_in_production(): void
    {
        // The provider guard must fail closed rather than serve predictable
        // credentials in production.
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.demo_mode' => true]);

        $this->expectException(\LogicException::class);

        (new AppServiceProvider($this->app))->boot();
    }
}
