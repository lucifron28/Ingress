<?php

namespace Tests\Feature;

use App\Actions\RegisterForEvent;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $student1;
    private User $student2;
    private User $organizer;
    private Venue $venue;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizer = User::factory()->create([
            'role' => User::ROLE_ORGANIZER,
        ]);

        $this->student1 = User::factory()->create([
            'name' => 'Carlo Mendoza',
            'email' => 'student1@mseuf.edu.ph',
            'student_id' => '2024-01001',
            'role' => User::ROLE_STUDENT,
        ]);

        $this->student2 = User::factory()->create([
            'name' => 'Patricia Reyes',
            'email' => 'student2@mseuf.edu.ph',
            'student_id' => '2024-01002',
            'role' => User::ROLE_STUDENT,
        ]);

        $this->venue = Venue::create([
            'name' => 'MSEUF Gymnasium',
            'code' => 'GYM',
            'location' => 'Main Campus',
            'default_capacity' => 1000,
        ]);

        $this->event = Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'Flagship Symposium 2026',
            'slug' => 'flagship-symposium-2026',
            'description' => 'University symposium event',
            'capacity' => 2, // Small capacity to easily test limits and concurrency
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
        ]);
    }

    public function test_student_can_register_once_and_receives_opaque_qr_ticket(): void
    {
        $response = $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration");

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'registration_id',
                'ticket' => ['id', 'public_id', 'status', 'issued_at'],
                'qr_payload',
            ]);

        $qrPayload = $response->json('qr_payload');
        $this->assertStringStartsWith('ingress:v1:ING-', $qrPayload);

        // Invariant: Zero PII in QR code
        $this->assertStringNotContainsString('Carlo', $qrPayload);
        $this->assertStringNotContainsString('student1@mseuf.edu.ph', $qrPayload);
        $this->assertStringNotContainsString('2024-01001', $qrPayload);

        // Verify database records
        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'user_id' => $this->student1->id,
            'status' => 'confirmed',
        ]);

        $ticket = Ticket::where('public_id', $response->json('ticket.public_id'))->first();
        $this->assertNotNull($ticket);
        $this->assertEquals(Ticket::STATUS_ACTIVE, $ticket->status);
        $this->assertEquals(64, strlen($ticket->qr_hash)); // SHA-256 hash length
    }

    public function test_duplicate_registration_is_rejected(): void
    {
        // First registration
        $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(201);

        // Second registration attempt for same student on same event
        $duplicateResponse = $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration");

        $duplicateResponse->assertStatus(422)
            ->assertJsonValidationErrors(['registration']);
    }

    public function test_registration_outside_allowed_window_is_rejected(): void
    {
        $futureEvent = Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'Future Event',
            'slug' => 'future-event',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->addDays(2), // Opens in 2 days
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$futureEvent->id}/registration");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['registration']);
    }

    public function test_capacity_cannot_be_exceeded(): void
    {
        $student3 = User::factory()->create(['role' => User::ROLE_STUDENT]);

        // Capacity is 2
        // Student 1 registers (1/2)
        $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(201);

        // Student 2 registers (2/2)
        $this->actingAs($this->student2)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(201);

        // Student 3 attempts to register (capacity full)
        $fullResponse = $this->actingAs($student3)
            ->postJson("/api/v1/events/{$this->event->id}/registration");

        $fullResponse->assertStatus(422)
            ->assertJsonValidationErrors(['capacity']);

        // Invariant: confirmed registrations <= capacity
        $confirmedCount = Registration::where('event_id', $this->event->id)
            ->where('status', 'confirmed')
            ->count();
        $this->assertEquals(2, $confirmedCount);
        $this->assertLessThanOrEqual($this->event->capacity, $confirmedCount);
    }

    public function test_competing_requests_for_final_seat_cannot_both_succeed(): void
    {
        // Event with exactly 1 seat
        $singleSeatEvent = Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'Single Seat Competition',
            'slug' => 'single-seat-comp',
            'capacity' => 1,
            'registration_opens_at' => Carbon::now()->subHour(),
            'registration_closes_at' => Carbon::now()->addDay(),
            'starts_at' => Carbon::now()->addDays(2),
            'ends_at' => Carbon::now()->addDays(2)->addHours(1),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $action = new RegisterForEvent();

        // Simulate two competing requests attempting to acquire the final seat
        $successCount = 0;
        $failureCount = 0;

        try {
            $action->execute($this->student1, $singleSeatEvent->id);
            $successCount++;
        } catch (ValidationException) {
            $failureCount++;
        }

        try {
            $action->execute($this->student2, $singleSeatEvent->id);
            $successCount++;
        } catch (ValidationException) {
            $failureCount++;
        }

        $this->assertEquals(1, $successCount, 'Exactly one request must acquire the final seat.');
        $this->assertEquals(1, $failureCount, 'Competing request must be rejected when capacity is exhausted.');

        $confirmed = Registration::where('event_id', $singleSeatEvent->id)
            ->where('status', 'confirmed')
            ->count();
        $this->assertEquals(1, $confirmed);
    }

    public function test_cancellation_revokes_ticket(): void
    {
        $regResponse = $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration");

        $ticketId = $regResponse->json('ticket.id');

        $cancelResponse = $this->actingAs($this->student1)
            ->deleteJson("/api/v1/events/{$this->event->id}/registration");

        $cancelResponse->assertStatus(200)
            ->assertJson(['message' => 'Registration cancelled and digital ticket revoked.']);

        $ticket = Ticket::find($ticketId);
        $this->assertEquals(Ticket::STATUS_REVOKED, $ticket->status);
        $this->assertNotNull($ticket->revoked_at);
    }

    public function test_student_can_list_their_tickets(): void
    {
        $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration");

        $response = $this->actingAs($this->student1)
            ->getJson('/api/v1/me/tickets');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'public_id',
                        'status',
                        'event' => ['id', 'title'],
                        'attendee' => ['name', 'email'],
                    ],
                ],
            ]);

        $this->assertCount(1, $response->json('data'));
    }

    public function test_student_cannot_view_another_students_ticket(): void
    {
        $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration");

        $ticket = Ticket::whereHas('registration', fn ($q) => $q->where('user_id', $this->student1->id))->first();

        // Student 2 attempts to fetch Student 1's ticket
        $response = $this->actingAs($this->student2)
            ->getJson("/api/v1/tickets/{$ticket->id}");

        $response->assertStatus(403);
    }
}
