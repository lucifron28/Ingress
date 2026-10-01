<?php

namespace Tests\Feature;

use App\Actions\RegisterForEvent;
use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $staffGateA;
    private User $staffGateB;
    private User $unauthorizedStaff;
    private User $student;
    private Event $event;
    private Event $otherEvent;
    private AccessPoint $gateA;
    private AccessPoint $gateB;
    private string $validQrPayload;
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);

        $this->staffGateA = User::factory()->create([
            'name' => 'Staff Alex Rivera',
            'email' => 'staff.alex@mseuf.edu.ph',
            'role' => User::ROLE_STAFF,
        ]);

        $this->staffGateB = User::factory()->create([
            'name' => 'Staff Bea Ramos',
            'email' => 'staff.bea@mseuf.edu.ph',
            'role' => User::ROLE_STAFF,
        ]);

        $this->unauthorizedStaff = User::factory()->create([
            'name' => 'Unauthorized Staff',
            'email' => 'unauth.staff@mseuf.edu.ph',
            'role' => User::ROLE_STAFF,
        ]);

        $this->student = User::factory()->create([
            'name' => 'Carlo Mendoza',
            'email' => 'student.carlo@mseuf.edu.ph',
            'role' => User::ROLE_STUDENT,
        ]);

        $venue = Venue::create([
            'name' => 'MSEUF Gymnasium',
            'code' => 'GYM',
            'location' => 'Main Campus',
            'default_capacity' => 1000,
        ]);

        // Main Event
        $this->event = Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => $venue->id,
            'title' => 'MSEUF Tech Expo 2026',
            'slug' => 'mseuf-tech-expo-2026',
            'capacity' => 100,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(2),
            'ends_at' => Carbon::now()->addDays(2)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        // Access Points
        $this->gateA = AccessPoint::create([
            'event_id' => $this->event->id,
            'name' => 'Gate A - West Wing',
            'code' => 'GATE-A',
            'is_active' => true,
        ]);

        $this->gateB = AccessPoint::create([
            'event_id' => $this->event->id,
            'name' => 'Gate B - East Wing',
            'code' => 'GATE-B',
            'is_active' => true,
        ]);

        // Assign Staff A & B to this event
        EventStaff::create([
            'event_id' => $this->event->id,
            'user_id' => $this->staffGateA->id,
            'access_point_id' => $this->gateA->id,
        ]);
        EventStaff::create([
            'event_id' => $this->event->id,
            'user_id' => $this->staffGateB->id,
            'access_point_id' => $this->gateB->id,
        ]);

        // Other Event for cross-event ticket test
        $this->otherEvent = Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => $venue->id,
            'title' => 'Other Campus Event',
            'slug' => 'other-campus-event',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(3),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        // Register student and obtain ticket
        $action = new RegisterForEvent();
        $result = $action->execute($this->student, $this->event->id);
        $this->validQrPayload = $result['qr_payload'];
        $this->ticket = $result['ticket'];
    }

    public function test_valid_ticket_is_admitted_at_gate_a(): void
    {
        $scanId = (string) Str::uuid();

        $response = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'admitted',
                'message' => 'Ticket Valid',
                'admission' => [
                    'access_point' => 'Gate A - West Wing',
                    'scanned_by' => 'Staff Alex Rivera',
                ],
                'ticket' => [
                    'public_id' => $this->ticket->public_id,
                    'attendee_name' => 'Carlo Mendoza',
                ],
            ]);

        $this->assertDatabaseHas('admissions', [
            'ticket_id' => $this->ticket->id,
            'access_point_id' => $this->gateA->id,
            'client_scan_id' => $scanId,
        ]);
    }

    public function test_anti_passback_duplicate_scan_is_immediately_rejected_at_gate_b(): void
    {
        $scanId1 = (string) Str::uuid();
        $scanId2 = (string) Str::uuid();

        // 1. First scan: Admitted at Gate A
        $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId1,
            ])
            ->assertStatus(200);

        // 2. Second scan: Same ticket scanned immediately at Gate B
        $responseGateB = $this->actingAs($this->staffGateB)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateB->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId2,
            ]);

        $responseGateB->assertStatus(409)
            ->assertJson([
                'status' => 'already_admitted',
                'message' => 'Already Checked In',
                'previous_admission' => [
                    'access_point' => 'Gate A - West Wing',
                    'scanned_by' => 'Staff Alex Rivera',
                ],
            ]);

        // Verify only 1 admission row exists in the database
        $admissionCount = Admission::where('ticket_id', $this->ticket->id)->count();
        $this->assertEquals(1, $admissionCount, 'Anti-passback must prevent second admission row creation.');
    }

    public function test_scan_request_is_idempotent_for_same_client_scan_id(): void
    {
        $scanId = (string) Str::uuid();

        // Initial submission
        $res1 = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId,
            ]);
        $res1->assertStatus(200);

        // Network retry with identical client_scan_id
        $res2 = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId,
            ]);

        $res2->assertStatus(200)
            ->assertJson([
                'status' => 'admitted',
                'message' => 'Ticket Valid',
            ]);

        $this->assertEquals(1, Admission::where('client_scan_id', $scanId)->count());
    }

    public function test_idempotency_key_cannot_be_reused_for_different_ticket(): void
    {
        $scanId = (string) Str::uuid();

        // 1. Initial scan for Student 1 ticket
        $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId,
            ])
            ->assertStatus(200);

        // 2. Second student registers and gets ticket
        $student2 = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $action = new RegisterForEvent();
        $student2Result = $action->execute($student2, $this->event->id);

        // 3. Scanner sends the same client_scan_id for Student 2's ticket
        $conflictResponse = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $student2Result['qr_payload'],
                'client_scan_id' => $scanId, // Reused scan ID
            ]);

        $conflictResponse->assertStatus(409)
            ->assertJson([
                'status' => 'idempotency_conflict',
                'message' => 'Idempotency Key Conflict',
            ]);
    }

    public function test_idempotency_key_cannot_be_reused_for_different_access_point(): void
    {
        $scanId = (string) Str::uuid();

        // 1. Initial scan at Gate A
        $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId,
            ])
            ->assertStatus(200);

        // 2. Same scan ID sent for Gate B
        $conflictResponse = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateB->id, // Different gate
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId, // Reused scan ID
            ]);

        $conflictResponse->assertStatus(409)
            ->assertJson([
                'status' => 'idempotency_conflict',
                'message' => 'Idempotency Key Conflict',
            ]);
    }
    public function test_ticket_for_another_event_is_rejected(): void
    {
        // Issue ticket for otherEvent
        $action = new RegisterForEvent();
        $otherResult = $action->execute($this->student, $this->otherEvent->id);

        $response = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $otherResult['qr_payload'],
                'client_scan_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'wrong_event',
                'message' => 'Wrong Event',
            ]);
    }

    public function test_revoked_ticket_cannot_be_admitted(): void
    {
        $this->ticket->update([
            'status' => Ticket::STATUS_REVOKED,
            'revoked_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'revoked_ticket',
                'message' => 'Revoked Ticket',
            ]);
    }

    public function test_unauthorized_staff_cannot_scan(): void
    {
        $response = $this->actingAs($this->unauthorizedStaff)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'unauthorized_staff',
            ]);
    }

    public function test_malformed_or_counterfeit_qr_payload_is_rejected(): void
    {
        // Malformed string
        $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => 'random_junk_string',
                'client_scan_id' => (string) Str::uuid(),
            ])
            ->assertStatus(422)
            ->assertJson(['status' => 'invalid_ticket']);

        // Counterfeit secret
        $fakePayload = "ingress:v1:{$this->ticket->public_id}:" . str_repeat('0', 64);
        $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $fakePayload,
                'client_scan_id' => (string) Str::uuid(),
            ])
            ->assertStatus(422)
            ->assertJson([
                'status' => 'invalid_ticket',
                'message' => 'Invalid Ticket',
            ]);
    }
}
