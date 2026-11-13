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

    public function test_create_form_defaults_registration_to_close_before_the_event_starts(): void
    {
        $this->freezeTime();

        $this->actingAs($this->organizerA)
            ->get('/organizer/events/create')
            ->assertOk()
            ->assertSee('name="registration_closes_at" value="'.now()->addDay()->format('Y-m-d\\TH:i').'"', false)
            ->assertSee('name="starts_at" value="'.now()->addDays(2)->setHour(8)->setMinute(0)->format('Y-m-d\\TH:i').'"', false);
    }

    public function test_organizer_cannot_create_an_event_that_accepts_registrations_after_it_starts(): void
    {
        $this->freezeTime();
        $now = Carbon::now()->toImmutable();

        $this->actingAs($this->organizerA)
            ->post('/organizer/events', [
                'venue_id' => $this->venue->id,
                'title' => 'Late Registration Event',
                'capacity' => 45,
                'registration_opens_at' => $now->toDateTimeString(),
                'registration_closes_at' => $now->addDays(3)->toDateTimeString(),
                'starts_at' => $now->addDays(2)->toDateTimeString(),
                'ends_at' => $now->addDays(4)->toDateTimeString(),
            ])
            ->assertSessionHasErrors('registration_closes_at');

        $this->assertDatabaseMissing('events', ['title' => 'Late Registration Event']);
    }

    public function test_organizer_cannot_edit_registration_cutoff_to_after_event_start(): void
    {
        $this->freezeTime();
        $now = Carbon::now()->toImmutable();
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Cutoff Edit Event',
            'slug' => 'cutoff-edit-event',
            'capacity' => 45,
            'registration_opens_at' => $now,
            'registration_closes_at' => $now->addDay(),
            'starts_at' => $now->addDays(2),
            'ends_at' => $now->addDays(3),
            'status' => Event::STATUS_DRAFT,
        ]);

        $this->actingAs($this->organizerA)
            ->put("/organizer/events/{$event->id}", [
                'venue_id' => $this->venue->id,
                'title' => $event->title,
                'capacity' => $event->capacity,
                'registration_opens_at' => $now->toDateTimeString(),
                'registration_closes_at' => $now->addDays(3)->toDateTimeString(),
                'starts_at' => $now->addDays(2)->toDateTimeString(),
                'ends_at' => $now->addDays(4)->toDateTimeString(),
            ])
            ->assertSessionHasErrors('registration_closes_at');

        $this->assertSame(
            $now->addDay()->toDateTimeString(),
            $event->fresh()->registration_closes_at->toDateTimeString(),
        );
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

    public function test_organizer_can_assign_a_student_as_scanner_staff_without_changing_their_account_role(): void
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

        $response->assertSessionHas('success');
        $this->assertTrue($event->staffAssignments()->where('user_id', $this->student->id)->exists());
        $this->assertSame(User::ROLE_STUDENT, $this->student->fresh()->role);
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
