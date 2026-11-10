<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_dashboard_renders_existing_metrics_and_create_action(): void
    {
        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create([
            'name' => 'Main Hall',
            'code' => 'MAIN-HALL',
            'default_capacity' => 12000,
        ]);

        $this->createEvent($organizer, $venue, 'Published Campus Forum', 10000, Event::STATUS_PUBLISHED);
        $this->createEvent($organizer, $venue, 'Draft Campus Workshop', 75, Event::STATUS_DRAFT);

        $response = $this->actingAs($organizer)->get(route('organizer.dashboard'));

        $response->assertOk()
            ->assertSee('Organizer Dashboard')
            ->assertSee('Manage your campus events')
            ->assertSee('Total Events')
            ->assertSee('Published Events')
            ->assertSee('Draft Events')
            ->assertSee('Total Capacity')
            ->assertSee('2')
            ->assertSee('1')
            ->assertSee('10,075')
            ->assertSee('Create New Event')
            ->assertSee(route('organizer.events.create'), false);
    }

    private function createEvent(User $organizer, Venue $venue, string $title, int $capacity, string $status): Event
    {
        return Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => $venue->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'capacity' => $capacity,
            'registration_opens_at' => '2026-11-09 09:00:00',
            'registration_closes_at' => '2026-11-11 09:00:00',
            'starts_at' => '2026-11-20 09:00:00',
            'ends_at' => '2026-11-20 11:00:00',
            'status' => $status,
        ]);
    }
}
