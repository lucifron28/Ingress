<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $organizerA;

    private User $organizerB;

    private User $student;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizerA = User::factory()->create([
            'email' => 'organizerA@mseuf.edu.ph',
            'role' => User::ROLE_ORGANIZER,
        ]);

        $this->organizerB = User::factory()->create([
            'email' => 'organizerB@mseuf.edu.ph',
            'role' => User::ROLE_ORGANIZER,
        ]);

        $this->student = User::factory()->create([
            'email' => 'student@mseuf.edu.ph',
            'role' => User::ROLE_STUDENT,
        ]);

        $this->venue = Venue::create([
            'name' => 'MSEUF Gymnasium',
            'code' => 'GYM',
            'location' => 'Main Campus',
            'default_capacity' => 1000,
        ]);
    }

    public function test_organizer_can_create_event_with_default_access_points(): void
    {
        $response = $this->actingAs($this->organizerA)
            ->post('/organizer/events', [
                'venue_id' => $this->venue->id,
                'title' => 'C-Sharpening Workshop',
                'description' => 'A workshop on C# and .NET.',
                'capacity' => 45,
                'registration_opens_at' => Carbon::now()->toDateTimeString(),
                'registration_closes_at' => Carbon::now()->addDays(5)->toDateTimeString(),
                'starts_at' => Carbon::now()->addDays(6)->toDateTimeString(),
                'ends_at' => Carbon::now()->addDays(6)->addHours(4)->toDateTimeString(),
            ]);

        $event = Event::where('title', 'C-Sharpening Workshop')->first();
        $this->assertNotNull($event);
        $this->assertEquals(Event::STATUS_DRAFT, $event->status);
        $this->assertEquals($this->organizerA->id, $event->organizer_id);

        $response->assertRedirect(route('organizer.events.show', $event));

        $this->assertCount(3, $event->accessPoints);
        $this->assertTrue($event->accessPoints()->where('code', 'MAIN')->exists());
        $this->assertTrue($event->accessPoints()->where('code', 'GATE-A')->exists());
        $this->assertTrue($event->accessPoints()->where('code', 'GATE-B')->exists());
    }

    public function test_event_island_is_limited_to_the_event_overview_page(): void
    {
        $startsAt = Carbon::parse('2027-02-15 08:30:00');
        $endsAt = Carbon::parse('2027-02-15 16:45:00');
        $registrationOpensAt = Carbon::parse('2027-01-10 09:15:00');
        $registrationClosesAt = Carbon::parse('2027-02-10 17:30:00');

        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Island Navigation Test',
            'slug' => 'island-navigation-test',
            'capacity' => 100,
            'description' => 'A readable overview with the full event details.',
            're_entry_allowed' => true,
            'identity_verification_level' => 'id_check',
            'registration_opens_at' => $registrationOpensAt,
            'registration_closes_at' => $registrationClosesAt,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $overview = $this->actingAs($this->organizerA)
            ->get(route('organizer.events.show', $event));

        $overview->assertOk()
            ->assertSee('data-event-island', false)
            ->assertSee('data-island-target="event-gates"', false)
            ->assertSee('Capacity Overview')
            ->assertSee('Event dates')
            ->assertSee('Registration period')
            ->assertSee('About this Event')
            ->assertSee('A readable overview with the full event details.')
            ->assertSee($startsAt->format('F j, Y'))
            ->assertSee($startsAt->format('g:i A'))
            ->assertSee($endsAt->format('g:i A'))
            ->assertSee($registrationOpensAt->format('F j, Y'))
            ->assertSee($registrationOpensAt->format('g:i A'))
            ->assertSee($registrationClosesAt->format('g:i A'))
            ->assertSee('Admission policies')
            ->assertSee('No transfer requests yet.')
            ->assertSee('Disabled');

        $page = $overview->getContent();
        $capacityPosition = strpos($page, 'id="event-capacity-title"');
        $schedulePosition = strpos($page, 'id="event-details-title"');
        $this->assertNotFalse($capacityPosition);
        $this->assertNotFalse($schedulePosition);
        $this->assertLessThan($schedulePosition, $capacityPosition);

        $this->actingAs($this->organizerA)
            ->get(route('organizer.events.edit', $event))
            ->assertOk()
            ->assertDontSee('data-event-island', false);
    }

    public function test_create_and_edit_forms_use_four_grouped_sections(): void
    {
        $create = $this->actingAs($this->organizerA)
            ->get(route('organizer.events.create'))
            ->assertOk()
            ->assertSee('Basic Information')
            ->assertSee('Event Schedule')
            ->assertSee('Registration Settings')
            ->assertSee('Admission Policies')
            ->assertSee('Event Starts')
            ->assertSee('Registration Opens')
            ->assertSee('Create Event (Draft)');

        $this->assertSame(4, substr_count($create->getContent(), 'event-form-section__header'));

        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Grouped Form Test',
            'slug' => 'grouped-form-test',
            'capacity' => 100,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(4),
            'status' => Event::STATUS_DRAFT,
        ]);

        $edit = $this->actingAs($this->organizerA)
            ->get(route('organizer.events.edit', $event))
            ->assertOk()
            ->assertSee('Basic Information')
            ->assertSee('Event Schedule')
            ->assertSee('Registration Settings')
            ->assertSee('Admission Policies')
            ->assertSee('Save Changes');

        $this->assertSame(4, substr_count($edit->getContent(), 'event-form-section__header'));
    }

    public function test_organizer_can_publish_draft_event(): void
    {
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Orientation 2026',
            'slug' => 'orientation-2026',
            'capacity' => 100,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/publish");

        $response->assertRedirect(route('organizer.events.show', $event));
        $this->assertEquals(Event::STATUS_PUBLISHED, $event->fresh()->status);
    }

    public function test_organizer_can_cancel_published_event(): void
    {
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Cancelled Fest',
            'slug' => 'cancelled-fest',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/cancel");

        $response->assertRedirect(route('organizer.events.show', $event));
        $this->assertEquals(Event::STATUS_CANCELLED, $event->fresh()->status);
    }

    public function test_organizer_cannot_modify_another_organizers_event(): void
    {
        $eventB = Event::create([
            'organizer_id' => $this->organizerB->id,
            'venue_id' => $this->venue->id,
            'title' => 'Organizer B Private Event',
            'slug' => 'organizer-b-private',
            'capacity' => 20,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($this->organizerA)
            ->put("/organizer/events/{$eventB->id}", [
                'venue_id' => $this->venue->id,
                'title' => 'Hacked Title',
                'capacity' => 999,
                'registration_opens_at' => Carbon::now()->toDateTimeString(),
                'registration_closes_at' => Carbon::now()->addDays(2)->toDateTimeString(),
                'starts_at' => Carbon::now()->addDays(3)->toDateTimeString(),
                'ends_at' => Carbon::now()->addDays(3)->addHours(2)->toDateTimeString(),
            ]);

        $response->assertStatus(403);
        $this->assertEquals('Organizer B Private Event', $eventB->fresh()->title);

        $cancelResponse = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$eventB->id}/cancel");

        $cancelResponse->assertStatus(403);
    }

    public function test_student_cannot_access_organizer_event_routes(): void
    {
        $response = $this->actingAs($this->student)
            ->get('/organizer/events/create');

        $response->assertRedirect(route('login'));
    }

    public function test_organizer_can_add_custom_access_point_and_assign_staff(): void
    {
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Staff Test Event',
            'slug' => 'staff-test-event',
            'capacity' => 100,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $staffUser = User::factory()->create([
            'email' => 'scanner.staff@mseuf.edu.ph',
            'role' => User::ROLE_STAFF,
        ]);

        $gateResponse = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/access-points", [
                'name' => 'South Turnstile',
                'code' => 'GATE-S',
            ]);

        $gateResponse->assertSessionHas('success');
        $gate = $event->accessPoints()->where('code', 'GATE-S')->first();
        $this->assertNotNull($gate);

        $assignResponse = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/staff", [
                'user_id' => $staffUser->id,
                'access_point_id' => $gate->id,
            ]);

        $assignResponse->assertSessionHas('success');
        $this->assertTrue(
            $event->staffAssignments()
                ->where('user_id', $staffUser->id)
                ->where('access_point_id', $gate->id)
                ->exists()
        );
    }

    public function test_organizer_cannot_assign_non_staff_user_to_event(): void
    {
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Validation Test Event',
            'slug' => 'val-test-event',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/staff", [
                'user_id' => $this->student->id, // Student role, not staff!
            ]);

        $response->assertSessionHasErrors(['user_id']);
        $this->assertFalse($event->staffAssignments()->where('user_id', $this->student->id)->exists());
    }

    public function test_organizer_cannot_assign_gate_from_another_event(): void
    {
        $event1 = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Event 1',
            'slug' => 'event-1',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);

        $event2 = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Event 2',
            'slug' => 'event-2',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);

        $event2Gate = AccessPoint::create([
            'event_id' => $event2->id,
            'name' => 'Event 2 Gate',
            'code' => 'E2-GATE',
        ]);

        $staffUser = User::factory()->create(['role' => User::ROLE_STAFF]);

        $response = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event1->id}/staff", [
                'user_id' => $staffUser->id,
                'access_point_id' => $event2Gate->id, // Belongs to event 2, not event 1!
            ]);

        $response->assertSessionHasErrors(['access_point_id']);
    }

    public function test_capacity_cannot_be_lowered_below_confirmed_registrations(): void
    {
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Capacity Floor Event',
            'slug' => 'capacity-floor-event',
            'capacity' => 500,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(3),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        User::factory()->count(420)->create(['role' => User::ROLE_STUDENT])
            ->each(function (User $student) use ($event) {
                Registration::create([
                    'event_id' => $event->id,
                    'user_id' => $student->id,
                    'status' => Registration::STATUS_CONFIRMED,
                ]);
            });

        $payload = fn (int $capacity) => [
            'venue_id' => $this->venue->id,
            'title' => $event->title,
            'description' => null,
            'capacity' => $capacity,
            'registration_opens_at' => Carbon::now()->subDay()->toDateTimeString(),
            'registration_closes_at' => Carbon::now()->addDays(5)->toDateTimeString(),
            'starts_at' => Carbon::now()->addDays(6)->toDateTimeString(),
            'ends_at' => Carbon::now()->addDays(6)->addHours(3)->toDateTimeString(),
        ];

        $this->actingAs($this->organizerA)
            ->put("/organizer/events/{$event->id}", $payload(300))
            ->assertSessionHasErrors(['capacity']);

        $this->assertEquals(500, $event->fresh()->capacity, 'rejected update must not change capacity');

        $this->actingAs($this->organizerA)
            ->put("/organizer/events/{$event->id}", $payload(420))
            ->assertSessionHasNoErrors();

        $this->assertEquals(420, $event->fresh()->capacity);
    }
}
