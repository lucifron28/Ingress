<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RuntimeBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function event(): Event
    {
        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'location' => 'Campus', 'default_capacity' => 20]);

        return Event::create([
            'organizer_id' => $organizer->id, 'venue_id' => $venue->id,
            'title' => 'Event', 'slug' => 'event', 'capacity' => 20,
            'registration_opens_at' => now()->subDay(), 'registration_closes_at' => now()->addDay(),
            'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => Event::STATUS_PUBLISHED,
        ]);
    }

    public function test_cloud_registration_works_but_scanner_is_unavailable(): void
    {
        config(['ingress.mode' => 'cloud']);
        $event = $this->event();
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);

        $this->actingAs($student)->postJson("/api/v1/events/{$event->id}/registration")->assertCreated();
        $this->actingAs($staff)->postJson("/api/v1/staff/events/{$event->id}/admissions", [])->assertStatus(503);
        $this->assertDatabaseCount('admissions', 0);
        $this->assertDatabaseHas('runtime_identity', ['id' => 1, 'mode' => 'cloud', 'identity' => 'cloud']);
    }

    public function test_node_cannot_register_modify_events_or_admit_before_handoff(): void
    {
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1']);
        $event = $this->event();
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $organizer = User::findOrFail($event->organizer_id);

        $this->actingAs($student)->postJson("/api/v1/events/{$event->id}/registration")->assertStatus(503);
        $this->actingAs($organizer)->post("/organizer/events/{$event->id}/cancel")->assertStatus(503);
        $this->actingAs($organizer)->postJson("/api/v1/staff/events/{$event->id}/admissions", [])->assertStatus(503);
        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('admissions', 0);
        $this->assertSame(Event::STATUS_PUBLISHED, $event->fresh()->status);
    }

    public function test_database_rejects_a_different_runtime_or_node_identity(): void
    {
        config(['ingress.mode' => 'node', 'ingress.node_id' => 'node-1']);
        $this->get('/login')->assertOk();
        config(['ingress.node_id' => 'node-2']);
        $this->get('/login')->assertStatus(503);
        config(['ingress.mode' => 'cloud']);
        $this->get('/login')->assertStatus(503);
        config(['ingress.mode' => 'legacy']);
        $this->get('/login')->assertStatus(503);
        $this->assertSame(1, DB::table('runtime_identity')->count());
    }

    public function test_invalid_configuration_fails_before_binding_database(): void
    {
        config(['ingress.mode' => 'node', 'ingress.node_id' => '']);
        $this->get('/login')->assertStatus(503);
        config(['ingress.mode' => 'typo']);
        $this->get('/login')->assertStatus(503);
        $this->assertDatabaseCount('runtime_identity', 0);
    }

    public function test_production_rejects_legacy_and_non_postgresql_databases(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['ingress.mode' => 'legacy']);
        $this->get('/login')->assertStatus(503);
        config(['ingress.mode' => 'cloud']);
        $this->get('/login')->assertStatus(503);
        $this->app->detectEnvironment(fn () => 'staging');
        config(['ingress.mode' => 'legacy']);
        $this->get('/login')->assertStatus(503);
        $this->assertDatabaseCount('runtime_identity', 0);
    }
}
