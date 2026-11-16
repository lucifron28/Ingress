<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Event;
use App\Models\EventStaff;
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
            ->assertSee(route('organizer.events.create'), false)
            ->assertSee('Sign Out')
            ->assertSee(route('logout'), false);
    }

    public function test_dashboard_lists_complete_event_details_and_existing_actions(): void
    {
        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $staffMember = User::factory()->create(['role' => User::ROLE_STAFF]);
        $venue = Venue::create([
            'name' => 'North Campus Gymnasium',
            'code' => 'NORTH-GYM',
            'default_capacity' => 1200,
        ]);
        $publishedEvent = $this->createEvent($organizer, $venue, 'Published Campus Forum', 1200, Event::STATUS_PUBLISHED);
        $publishedEvent->update(['ends_at' => '2026-11-21 11:00:00']);
        $accessPoint = AccessPoint::create([
            'event_id' => $publishedEvent->id,
            'name' => 'Main Gate',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        EventStaff::create([
            'event_id' => $publishedEvent->id,
            'user_id' => $staffMember->id,
            'access_point_id' => $accessPoint->id,
        ]);
        $draftEvent = $this->createEvent($organizer, $venue, 'Draft Campus Workshop', 75, Event::STATUS_DRAFT);
        $cancelledEvent = $this->createEvent($organizer, $venue, 'Cancelled Campus Concert', 500, Event::STATUS_CANCELLED);
        $completedEvent = $this->createEvent($organizer, $venue, 'Completed Campus Summit', 300, Event::STATUS_COMPLETED);

        $response = $this->actingAs($organizer)->get(route('organizer.dashboard'));

        $response->assertOk()
            ->assertSee('Managed Campus Events')
            ->assertSee('4 events')
            ->assertSee('Published Campus Forum')
            ->assertSee('North Campus Gymnasium')
            ->assertSee('Nov 20, 2026 · 5:00 PM PHT')
            ->assertSee('Nov 21, 2026 · 7:00 PM PHT')
            ->assertSee('1,200')
            ->assertSee('1 gate')
            ->assertSee('1 staff member')
            ->assertSee('Published')
            ->assertSee('Draft')
            ->assertSee('Cancelled')
            ->assertSee('Completed')
            ->assertSee('0 gates')
            ->assertSee('0 staff members')
            ->assertSee('View All')
            ->assertSee('Manage')
            ->assertSee('Attendance')
            ->assertSee(route('organizer.events.index'), false)
            ->assertSee(route('organizer.events.show', $publishedEvent), false)
            ->assertSee(route('organizer.attendance', $publishedEvent), false)
            ->assertSee(route('organizer.events.show', $draftEvent), false)
            ->assertSee(route('organizer.events.show', $cancelledEvent), false)
            ->assertSee(route('organizer.events.show', $completedEvent), false);
    }

    public function test_empty_dashboard_shows_create_event_call_to_action(): void
    {
        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);

        $response = $this->actingAs($organizer)->get(route('organizer.dashboard'));

        $response->assertOk()
            ->assertSee('No events created yet')
            ->assertSee('Create your first campus event to start managing registrations and admission.')
            ->assertSee('Create Event')
            ->assertSee(route('organizer.events.create'), false);
    }

    public function test_dashboard_actions_open_existing_pages_and_sign_out(): void
    {
        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create([
            'name' => 'Main Hall',
            'code' => 'MAIN-HALL',
            'default_capacity' => 12000,
        ]);
        $event = $this->createEvent($organizer, $venue, 'Published Campus Forum', 10000, Event::STATUS_PUBLISHED);

        $this->actingAs($organizer)
            ->get(route('organizer.events.create'))
            ->assertOk();
        $this->get(route('organizer.events.show', $event))->assertOk();
        $this->get(route('organizer.attendance', $event))->assertOk();
        $this->get(route('organizer.events.index'))->assertOk();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
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
