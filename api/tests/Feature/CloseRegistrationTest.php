<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function createEvent(string $status = Event::STATUS_PUBLISHED): Event
    {
        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'location' => 'Campus', 'default_capacity' => 20]);

        return Event::create([
            'organizer_id' => $organizer->id, 'venue_id' => $venue->id,
            'title' => 'Event', 'slug' => 'event', 'capacity' => 20,
            'registration_opens_at' => now()->subDay(), 'registration_closes_at' => now()->addDay(),
            'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => $status,
        ]);
    }

    public function test_owner_can_close_registration_and_retries_do_not_change_the_cutoff(): void
    {
        $this->freezeTime();
        $event = $this->createEvent();
        $owner = User::findOrFail($event->organizer_id);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $registered = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $ticketId = $this->actingAs($registered)->postJson("/api/v1/events/{$event->id}/registration")
            ->assertCreated()->json('ticket.id');
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration")
            ->assertRedirect(route('organizer.events.show', $event));
        $closedAt = $event->fresh()->registration_closed_at;
        $this->assertNotNull($closedAt);
        $this->travel(5)->minutes();
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration")->assertRedirect();
        $this->assertTrue($closedAt->equalTo($event->fresh()->registration_closed_at));
        $this->actingAs($student)->getJson("/api/v1/events/{$event->id}")
            ->assertOk()->assertJsonPath('event.is_registration_open', false)
            ->assertJsonPath('event.registration_closed_at', $closedAt->toISOString());
        $this->actingAs($student)->postJson("/api/v1/events/{$event->id}/registration")
            ->assertUnprocessable()->assertJsonValidationErrors('registration');
        $this->assertDatabaseCount('registrations', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->actingAs($registered)->getJson("/api/v1/tickets/{$ticketId}")
            ->assertOk()->assertJsonPath('ticket.status', 'active');
    }

    public function test_non_owner_cannot_close_and_draft_cannot_be_closed(): void
    {
        $event = $this->createEvent();
        $other = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->actingAs($other)->post("/organizer/events/{$event->id}/close-registration")->assertForbidden();
        $this->assertNull($event->fresh()->registration_closed_at);
        $event->update(['status' => Event::STATUS_DRAFT]);
        $this->actingAs(User::findOrFail($event->organizer_id))
            ->post("/organizer/events/{$event->id}/close-registration")
            ->assertSessionHasErrors('registration');
        $this->assertNull($event->fresh()->registration_closed_at);
    }

    public function test_editing_dates_does_not_reopen_registration_and_event_can_be_cancelled(): void
    {
        $event = $this->createEvent();
        $owner = User::findOrFail($event->organizer_id);
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration")->assertRedirect();
        $this->put("/organizer/events/{$event->id}", [
            'venue_id' => $event->venue_id, 'title' => $event->title, 'capacity' => $event->capacity,
            'registration_opens_at' => now()->subDay()->toDateTimeString(),
            'registration_closes_at' => now()->addDays(5)->toDateTimeString(),
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(6)->toDateTimeString(),
        ])->assertRedirect();
        $this->assertFalse($event->fresh()->isRegistrationOpen());
        $this->post("/organizer/events/{$event->id}/cancel")->assertRedirect();
        $this->assertSame(Event::STATUS_CANCELLED, $event->fresh()->status);
    }

    public function test_event_is_not_open_for_registration_after_it_ends(): void
    {
        $this->freezeTime();
        $event = $this->createEvent();
        $event->update(['ends_at' => now()->subSecond()]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->actingAs($student)->getJson("/api/v1/events/{$event->id}")
            ->assertOk()->assertJsonPath('event.is_registration_open', false);
        $this->postJson("/api/v1/events/{$event->id}/registration")->assertUnprocessable();
    }
}
