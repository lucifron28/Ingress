<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessPointManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private Venue $venue;

    private Event $event;

    private AccessPoint $gate;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ingress.mode' => 'cloud']);

        $this->owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->manager = User::factory()->create(['role' => User::ROLE_EVENT_MANAGER]);
        $this->venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 100]);
        $this->event = $this->createEvent($this->owner, $this->venue, 'Managed Event', 'managed-event');
        $this->event->managerAssignments()->create([
            'user_id' => $this->manager->id,
            'assigned_by_user_id' => $this->owner->id,
        ]);
        $this->gate = $this->event->accessPoints()->create([
            'name' => 'Gate A',
            'code' => 'GATE-A',
            'is_active' => true,
        ]);
    }

    public function test_assigned_event_manager_can_update_a_gate_and_view_the_saved_details(): void
    {
        $this->actingAs($this->manager)
            ->put("/organizer/events/{$this->event->id}/access-points/{$this->gate->id}", $this->gatePayload([
                'name' => 'North Entrance',
                'code' => 'north-entrance',
            ]))
            ->assertRedirect(route('organizer.events.show', $this->event))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('access_points', [
            'id' => $this->gate->id,
            'name' => 'North Entrance',
            'code' => 'NORTH-ENTRANCE',
            'is_active' => true,
        ]);

        $this->actingAs($this->manager)
            ->get(route('organizer.events.show', $this->event))
            ->assertOk()
            ->assertSee('North Entrance')
            ->assertSee('NORTH-ENTRANCE');
    }

    public function test_manager_cannot_edit_a_gate_for_an_unassigned_event(): void
    {
        $otherEvent = $this->createEvent($this->owner, $this->venue, 'Other Event', 'other-event');
        $otherGate = $otherEvent->accessPoints()->create(['name' => 'Gate B', 'code' => 'GATE-B']);

        $this->actingAs($this->manager)
            ->put("/organizer/events/{$otherEvent->id}/access-points/{$otherGate->id}", $this->gatePayload())
            ->assertForbidden();

        $this->assertDatabaseHas('access_points', ['id' => $otherGate->id, 'name' => 'Gate B']);
    }

    public function test_gate_from_another_event_cannot_be_edited_through_an_authorized_event_route(): void
    {
        $otherEvent = $this->createEvent($this->owner, $this->venue, 'Other Event', 'other-event');
        $otherGate = $otherEvent->accessPoints()->create(['name' => 'Gate B', 'code' => 'GATE-B']);

        $this->actingAs($this->owner)
            ->put("/organizer/events/{$this->event->id}/access-points/{$otherGate->id}", $this->gatePayload())
            ->assertNotFound();

        $this->assertDatabaseHas('access_points', ['id' => $otherGate->id, 'name' => 'Gate B']);
    }

    public function test_gate_details_are_locked_after_snapshot_preparation(): void
    {
        $this->event->forceFill(['snapshot_prepared_at' => now()])->save();

        $this->actingAs($this->manager)
            ->put("/organizer/events/{$this->event->id}/access-points/{$this->gate->id}", $this->gatePayload([
                'name' => 'Changed Gate',
            ]))
            ->assertSessionHasErrors('event');

        $this->assertDatabaseHas('access_points', ['id' => $this->gate->id, 'name' => 'Gate A']);
    }

    public function test_gate_details_cannot_change_after_admission_history_exists(): void
    {
        $registration = Registration::create([
            'event_id' => $this->event->id,
            'attendee_type' => 'guest',
            'guest_name' => 'Guest Attendee',
            'guest_email' => 'guest@example.test',
            'status' => Registration::STATUS_CONFIRMED,
        ]);
        $ticket = Ticket::create([
            'registration_id' => $registration->id,
            'public_id' => 'ING-ATTENDANCE-0001',
            'qr_hash' => hash('sha256', 'known-ticket-secret'),
            'status' => Ticket::STATUS_ACTIVE,
            'credential_version' => 1,
            'issued_at' => now(),
        ]);
        Admission::create([
            'ticket_id' => $ticket->id,
            'access_point_id' => $this->gate->id,
            'scanned_by_user_id' => $this->manager->id,
            'client_scan_id' => (string) Str::uuid(),
            'credential_version' => 1,
            'direction' => Admission::DIRECTION_ENTRY,
            'admitted_at' => now(),
        ]);

        $this->actingAs($this->manager)
            ->put("/organizer/events/{$this->event->id}/access-points/{$this->gate->id}", $this->gatePayload([
                'name' => 'Renamed Gate',
            ]))
            ->assertSessionHasErrors('access_point');

        $this->assertDatabaseHas('access_points', ['id' => $this->gate->id, 'name' => 'Gate A']);
    }

    public function test_gate_cannot_be_deactivated_while_scanner_staff_are_assigned_to_it(): void
    {
        $scanner = User::factory()->create(['role' => User::ROLE_STUDENT]);
        EventStaff::create([
            'event_id' => $this->event->id,
            'user_id' => $scanner->id,
            'access_point_id' => $this->gate->id,
        ]);

        $this->actingAs($this->manager)
            ->put("/organizer/events/{$this->event->id}/access-points/{$this->gate->id}", $this->gatePayload([
                'is_active' => '0',
            ]))
            ->assertSessionHasErrors('access_point');

        $this->assertDatabaseHas('access_points', ['id' => $this->gate->id, 'is_active' => true]);
    }

    public function test_scanner_staff_cannot_be_assigned_to_an_inactive_gate(): void
    {
        $scanner = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->gate->forceFill(['is_active' => false])->save();

        $this->actingAs($this->manager)
            ->post(route('organizer.events.staff.assign', $this->event), [
                'user_id' => $scanner->id,
                'access_point_id' => $this->gate->id,
            ])
            ->assertSessionHasErrors('access_point_id');

        $this->assertDatabaseMissing('event_staff', [
            'event_id' => $this->event->id,
            'user_id' => $scanner->id,
        ]);
    }

    public function test_gate_codes_are_unique_per_event_after_normalization(): void
    {
        $otherGate = $this->event->accessPoints()->create(['name' => 'Gate B', 'code' => 'GATE-B']);

        $this->actingAs($this->manager)
            ->put("/organizer/events/{$this->event->id}/access-points/{$otherGate->id}", $this->gatePayload([
                'code' => 'gate-a',
            ]))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseHas('access_points', ['id' => $otherGate->id, 'code' => 'GATE-B']);
    }

    /** @param array<string, string> $overrides
     * @return array{name: string, code: string, is_active: string}
     */
    private function gatePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Gate A',
            'code' => 'GATE-A',
            'is_active' => '1',
        ], $overrides);
    }

    private function createEvent(User $owner, Venue $venue, string $title, string $slug): Event
    {
        return Event::forceCreate([
            'organizer_id' => $owner->id,
            'venue_id' => $venue->id,
            'title' => $title,
            'slug' => $slug,
            'capacity' => 80,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);
    }
}
