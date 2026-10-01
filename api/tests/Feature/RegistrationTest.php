<?php

namespace Tests\Feature;

use App\Actions\RegisterForEvent;
use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\Ticket;
use Illuminate\Support\Str;
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
            'name' => 'Ron Vincent Cada',
            'email' => 'a23-35524@student.mseuf.edu.ph',
            'student_id' => 'A23-35524',
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
        $this->assertStringNotContainsString('Ron', $qrPayload);
        $this->assertStringNotContainsString('a23-35524@student.mseuf.edu.ph', $qrPayload);
        $this->assertStringNotContainsString('A23-35524', $qrPayload);

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
        $response->assertStatus(200);
        $response->assertJsonStructure([
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

    public function test_student_can_reregister_after_cancellation_and_rotates_qr_secret(): void
    {
        $gate = AccessPoint::create([
            'event_id' => $this->event->id,
            'name' => 'Main Gate',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        EventStaff::create([
            'event_id' => $this->event->id,
            'user_id' => $staff->id,
            'access_point_id' => $gate->id,
        ]);

        // 1. Initial registration
        $reg1Res = $this->actingAs($this->student1, 'sanctum')
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $reg1Res->assertStatus(201);
        $oldQrPayload = $reg1Res->json('qr_payload');

        // 2. Cancellation
        $this->actingAs($this->student1, 'sanctum')
            ->deleteJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(200);

        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'user_id' => $this->student1->id,
            'status' => 'cancelled',
        ]);

        $reg2Res = $this->actingAs($this->student1, 'sanctum')
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $reg2Res->assertStatus(201);
        $newQrPayload = $reg2Res->json('qr_payload');

        // Exactly 1 row in registrations table for this user & event
        $this->assertEquals(1, Registration::where('event_id', $this->event->id)->where('user_id', $this->student1->id)->count());
        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'user_id' => $this->student1->id,
            'status' => 'confirmed',
        ]);

        // Payloads must differ due to cryptographic secret rotation
        $this->assertNotEquals($oldQrPayload, $newQrPayload);

        // 4. Old QR payload must be REJECTED by admission scanner
        $admitAction = new \App\Actions\AdmitTicket();
        $oldScanResult = $admitAction->execute(
            staff: $staff,
            eventId: $this->event->id,
            accessPointId: $gate->id,
            qrPayload: $oldQrPayload,
            clientScanId: (string) Str::uuid()
        );
        $this->assertEquals(422, $oldScanResult['http_code']);
        $this->assertEquals('invalid_ticket', $oldScanResult['status']);

        // 5. New QR payload must be ADMITTED by admission scanner
        $newScanResult = $admitAction->execute(
            staff: $staff,
            eventId: $this->event->id,
            accessPointId: $gate->id,
            qrPayload: $newQrPayload,
            clientScanId: (string) Str::uuid()
        );
        $this->assertEquals(200, $newScanResult['http_code']);
        $this->assertEquals('admitted', $newScanResult['status']);
    }

    public function test_reregistration_after_admission_issues_a_fresh_admittable_credential(): void
    {
        $gate = AccessPoint::create([
            'event_id' => $this->event->id,
            'name' => 'Main Gate',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        EventStaff::create([
            'event_id' => $this->event->id,
            'user_id' => $staff->id,
            'access_point_id' => $gate->id,
        ]);

        $admitAction = new \App\Actions\AdmitTicket();

        // 1. Register, then admit the attendee through the gate.
        $firstRegistration = $this->actingAs($this->student1, 'sanctum')
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $oldQrPayload = $firstRegistration->json('qr_payload');
        $ticketId = $firstRegistration->json('ticket.id');

        $firstAdmission = $admitAction->execute(
            staff: $staff,
            eventId: $this->event->id,
            accessPointId: $gate->id,
            qrPayload: $oldQrPayload,
            clientScanId: (string) Str::uuid()
        );
        $this->assertEquals('admitted', $firstAdmission['status']);

        // 2. Cancel, then register again.
        $this->actingAs($this->student1, 'sanctum')
            ->deleteJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(200);

        $secondRegistration = $this->actingAs($this->student1, 'sanctum')
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $secondRegistration->assertStatus(201);
        $newQrPayload = $secondRegistration->json('qr_payload');

        // 3. The retired credential is invalid.
        $oldScan = $admitAction->execute(
            staff: $staff,
            eventId: $this->event->id,
            accessPointId: $gate->id,
            qrPayload: $oldQrPayload,
            clientScanId: (string) Str::uuid()
        );
        $this->assertEquals('invalid_ticket', $oldScan['status']);

        // Re-registration reuses the row, but the new credential must remain admittable.
        $newScan = $admitAction->execute(
            staff: $staff,
            eventId: $this->event->id,
            accessPointId: $gate->id,
            qrPayload: $newQrPayload,
            clientScanId: (string) Str::uuid()
        );
        $this->assertEquals(200, $newScan['http_code']);
        $this->assertEquals('admitted', $newScan['status']);

        // 5. Audit history is retained, but only one admission belongs to the
        //    current credential issuance.
        $this->assertEquals(2, Admission::where('ticket_id', $ticketId)->count());
        $this->assertEquals(
            1,
            Admission::where('ticket_id', $ticketId)
                ->where('credential_version', Ticket::find($ticketId)->credential_version)
                ->count()
        );

        // 6. Scanners see the ticket as admitted exactly once more.
        $secondScanAtSameCredential = $admitAction->execute(
            staff: $staff,
            eventId: $this->event->id,
            accessPointId: $gate->id,
            qrPayload: $newQrPayload,
            clientScanId: (string) Str::uuid()
        );
        $this->assertEquals('already_admitted', $secondScanAtSameCredential['status']);
    }
}
