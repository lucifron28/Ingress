<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Event;
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

        // Verify default access points were provisioned
        $this->assertCount(3, $event->accessPoints);
        $this->assertTrue($event->accessPoints()->where('code', 'MAIN')->exists());
        $this->assertTrue($event->accessPoints()->where('code', 'GATE-A')->exists());
        $this->assertTrue($event->accessPoints()->where('code', 'GATE-B')->exists());
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

        // Organizer A tries to update Organizer B's event
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

        // Organizer A tries to cancel Organizer B's event
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

        // Add custom gate
        $gateResponse = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/access-points", [
                'name' => 'South Turnstile',
                'code' => 'GATE-S',
            ]);

        $gateResponse->assertSessionHas('success');
        $gate = $event->accessPoints()->where('code', 'GATE-S')->first();
        $this->assertNotNull($gate);

        // Assign staff to gate
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

        // Attempting to assign student as staff
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

        // Attempt to assign Event 1 staff to Event 2's gate
        $response = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event1->id}/staff", [
                'user_id' => $staffUser->id,
                'access_point_id' => $event2Gate->id, // Belongs to event 2, not event 1!
            ]);

        $response->assertSessionHasErrors(['access_point_id']);
    }
}
