<?php

namespace Tests\Feature;

use App\Actions\AdmitTicket;
use App\Actions\RegisterForEvent;
use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
            'firebase_uid' => 'firebase-student-registration-1',
            'email_verified_at' => now(),
        ]);

        $this->student2 = User::factory()->create([
            'name' => 'Patricia Reyes',
            'email' => 'student2@mseuf.edu.ph',
            'student_id' => '2024-01002',
            'role' => User::ROLE_STUDENT,
            'firebase_uid' => 'firebase-student-registration-2',
            'email_verified_at' => now(),
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
        $student3 = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'firebase_uid' => 'firebase-student-registration-3',
            'email_verified_at' => now(),
        ]);

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

        $action = new RegisterForEvent;

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

    public function test_attendee_cancellation_endpoint_does_not_exist(): void
    {
        $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(201);

        // DELETE route was removed from the API; returns 405 Method Not Allowed (only POST is registered)
        $deleteResponse = $this->actingAs($this->student1)
            ->deleteJson("/api/v1/events/{$this->event->id}/registration");

        $deleteResponse->assertStatus(405);

        // Registration and ticket remain active
        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'user_id' => $this->student1->id,
            'status' => Registration::STATUS_CONFIRMED,
        ]);
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

    public function test_repeated_registration_cannot_rotate_ticket_credential(): void
    {
        $regResponse = $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $regResponse->assertStatus(201);

        $ticket = Ticket::whereHas('registration', fn ($q) => $q->where('user_id', $this->student1->id))->first();
        $originalPublicId = $ticket->public_id;
        $originalQrHash = $ticket->qr_hash;
        $originalVersion = $ticket->credential_version;

        // Repeated registration attempt must fail and cannot alter or rotate the ticket
        $repeatedResponse = $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $repeatedResponse->assertStatus(422)
            ->assertJsonValidationErrors(['registration']);

        $ticket->refresh();
        $this->assertEquals($originalPublicId, $ticket->public_id);
        $this->assertEquals($originalQrHash, $ticket->qr_hash);
        $this->assertEquals($originalVersion, $ticket->credential_version);
        $this->assertEquals(Ticket::STATUS_ACTIVE, $ticket->status);
        $this->assertEquals(1, Registration::where('event_id', $this->event->id)->where('user_id', $this->student1->id)->count());
    }

    public function test_admitted_ticket_remains_admitted_and_cannot_be_reset_through_registration_endpoint(): void
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

        $admitAction = new AdmitTicket;

        // 1. Student registers and receives ticket
        $regResponse = $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $regResponse->assertStatus(201);
        $qrPayload = $regResponse->json('qr_payload');

        // 2. Staff admits the attendee at gate
        $admission = $admitAction->execute(
            staff: $staff,
            eventId: $this->event->id,
            accessPointId: $gate->id,
            qrPayload: $qrPayload,
            clientScanId: (string) Str::uuid()
        );
        $this->assertEquals('admitted', $admission['status']);

        // 3. Attendee attempts to call registration endpoint again
        $repeatResponse = $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $repeatResponse->assertStatus(422)
            ->assertJsonValidationErrors(['registration']);

        // 4. Ticket remains admitted; admission cannot be wiped or reset
        $secondScan = $admitAction->execute(
            staff: $staff,
            eventId: $this->event->id,
            accessPointId: $gate->id,
            qrPayload: $qrPayload,
            clientScanId: (string) Str::uuid()
        );
        $this->assertEquals('already_admitted', $secondScan['status']);
        $this->assertEquals(1, Admission::whereHas('ticket.registration', fn ($q) => $q->where('user_id', $this->student1->id))->count());
    }

    public function test_revoked_ticket_is_rejected_by_admission_engine(): void
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

        $regResponse = $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $regResponse->assertStatus(201);
        $qrPayload = $regResponse->json('qr_payload');

        $ticket = Ticket::whereHas('registration', fn ($q) => $q->where('user_id', $this->student1->id))->first();

        // Administratively revoke the ticket
        $ticket->update([
            'status' => Ticket::STATUS_REVOKED,
            'revoked_at' => Carbon::now(),
        ]);

        $admitAction = new AdmitTicket;
        $scanResult = $admitAction->execute(
            staff: $staff,
            eventId: $this->event->id,
            accessPointId: $gate->id,
            qrPayload: $qrPayload,
            clientScanId: (string) Str::uuid()
        );

        $this->assertEquals(422, $scanResult['http_code']);
        $this->assertEquals('revoked_ticket', $scanResult['status']);
        $this->assertEquals(0, Admission::where('ticket_id', $ticket->id)->count());
    }

    public function test_staff_cannot_self_register(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);

        $response = $this->actingAs($staff)
            ->postJson("/api/v1/events/{$this->event->id}/registration");

        $response->assertStatus(403);
        $this->assertEquals(0, Ticket::count());
        $this->assertEquals(0, Registration::count());
    }

    public function test_organizer_cannot_self_register(): void
    {
        $response = $this->actingAs($this->organizer)
            ->postJson("/api/v1/events/{$this->event->id}/registration");

        $response->assertStatus(403);
        $this->assertEquals(0, Ticket::count());
        $this->assertEquals(0, Registration::count());
    }

    public function test_admin_cannot_self_register(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/events/{$this->event->id}/registration");

        $response->assertStatus(403);
        $this->assertEquals(0, Ticket::count());
        $this->assertEquals(0, Registration::count());
    }

    public function test_non_student_attempts_do_not_consume_capacity_or_issue_tickets(): void
    {
        $initialCapacity = $this->event->capacity;
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($staff)->postJson("/api/v1/events/{$this->event->id}/registration")->assertStatus(403);
        $this->actingAs($this->organizer)->postJson("/api/v1/events/{$this->event->id}/registration")->assertStatus(403);
        $this->actingAs($admin)->postJson("/api/v1/events/{$this->event->id}/registration")->assertStatus(403);

        $this->assertEquals(0, Registration::where('event_id', $this->event->id)->count());
        $this->assertEquals(0, Ticket::count());
        $this->assertEquals($initialCapacity, $this->event->fresh()->capacity);

        // Verify student can still register and take a seat
        $this->actingAs($this->student1)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(201);

        $this->assertEquals(1, Registration::where('event_id', $this->event->id)->count());
        $this->assertEquals(1, Ticket::count());
    }
}
