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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EndToEndVerticalSliceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Executes the complete milestone acceptance flow:
     *
     * Organizer logs in
     *   ↓ creates event
     *   ↓ configures capacity + registration window
     *   ↓ adds access points (Gate A, Gate B)
     *   ↓ assigns event staff
     *   ↓ publishes event
     *
     * Student logs in (mobile API)
     *   ↓ discovers event
     *   ↓ registers (capacity checked transactionally)
     *   ↓ receives opaque QR ticket
     *
     * Staff Phone Gate A
     *   ↓ authenticates
     *   ↓ selects Gate A
     *   ↓ scans ticket (read-only VALID TICKET preview)
     *   ↓ staff confirms admission (transactional admission recorded)
     *
     * Staff Phone Gate B
     *   ↓ scans same ticket
     *   ↓ ALREADY CHECKED IN (previous gate & timestamp returned, no duplicate row)
     *
     * Organizer Web
     *   ↓ views attendance dashboard
     *   ↓ metrics & recent scan feed reflect the admission
     */
    public function test_complete_vertical_slice_workflow(): void
    {
        // ---------------------------------------------------------
        // 1. SETUP ACTORS & VENUE
        // ---------------------------------------------------------
        $venue = Venue::create([
            'name' => 'MSEUF University Gymnasium',
            'code' => 'GYM',
            'location' => 'University Athletic Complex, Lucena City',
            'default_capacity' => 1500,
        ]);

        $organizer = User::factory()->create([
            'name' => 'Prof. Juan Dela Cruz',
            'email' => 'organizer@mseuf.edu.ph',
            'role' => User::ROLE_ORGANIZER,
            'password' => bcrypt('password'),
        ]);

        $student = User::factory()->create([
            'name' => 'Ron Vincent Cada',
            'email' => 'a23-35524@student.mseuf.edu.ph',
            'student_id' => 'A23-35524',
            'role' => User::ROLE_STUDENT,
            'password' => bcrypt('password'),
            'firebase_uid' => 'firebase-e2e-student',
            'email_verified_at' => now(),
        ]);

        $staffGateA = User::factory()->create([
            'name' => 'Staff Alex Rivera',
            'email' => 'staff.gate_a@mseuf.edu.ph',
            'role' => User::ROLE_STAFF,
            'password' => bcrypt('password'),
        ]);

        $staffGateB = User::factory()->create([
            'name' => 'Staff Bea Ramos',
            'email' => 'staff.gate_b@mseuf.edu.ph',
            'role' => User::ROLE_STAFF,
            'password' => bcrypt('password'),
        ]);

        // ---------------------------------------------------------
        // 2. ORGANIZER CREATES & PUBLISHES EVENT (WEB PORTAL)
        // ---------------------------------------------------------
        $this->actingAs($organizer);

        // Organizer creates event with capacity of 10
        $createResponse = $this->post('/organizer/events', [
            'venue_id' => $venue->id,
            'title' => 'Ingress Launch Showcase 2026',
            'description' => 'Official demonstration symposium for MSEUF Ingress ticketing platform.',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now()->subDay()->toDateTimeString(),
            'registration_closes_at' => Carbon::now()->addDays(5)->toDateTimeString(),
            'starts_at' => Carbon::now()->addDays(2)->setHour(9)->setMinute(0)->toDateTimeString(),
            'ends_at' => Carbon::now()->addDays(2)->setHour(17)->setMinute(0)->toDateTimeString(),
        ]);

        $event = Event::where('title', 'Ingress Launch Showcase 2026')->first();
        $this->assertNotNull($event);
        $this->assertEquals(Event::STATUS_DRAFT, $event->status);
        $this->assertEquals(10, $event->capacity);

        // Fetch auto-provisioned access points
        $gateA = $event->accessPoints()->where('code', 'GATE-A')->first();
        $gateB = $event->accessPoints()->where('code', 'GATE-B')->first();
        $this->assertNotNull($gateA);
        $this->assertNotNull($gateB);

        // Assign Staff Alex to Gate A and Staff Bea to Gate B
        $this->post("/organizer/events/{$event->id}/staff", [
            'user_id' => $staffGateA->id,
            'access_point_id' => $gateA->id,
        ])->assertSessionHas('success');

        $this->post("/organizer/events/{$event->id}/staff", [
            'user_id' => $staffGateB->id,
            'access_point_id' => $gateB->id,
        ])->assertSessionHas('success');

        // Publish Event
        $this->post("/organizer/events/{$event->id}/publish")
            ->assertRedirect(route('organizer.events.show', $event));
        $this->assertEquals(Event::STATUS_PUBLISHED, $event->fresh()->status);
        // Clear web session before mobile API actor interactions
        auth('web')->logout();
        session()->flush();

        // ---------------------------------------------------------
        // 3. STUDENT LOGS IN & DISCOVERS EVENT (MOBILE API)
        // ---------------------------------------------------------
        $studentLoginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'a23-35524@student.mseuf.edu.ph',
            'password' => 'password',
            'device_name' => 'Ron-Android-Phone',
        ]);
        $studentLoginRes->assertStatus(200);
        $studentToken = $studentLoginRes->json('token');
        $this->assertNotEmpty($studentToken);
        $this->actingAs($student, 'sanctum');

        // Student discovers published events
        $discoveryRes = $this->withHeader('Authorization', 'Bearer '.$studentToken)
            ->getJson('/api/v1/events');
        $discoveryRes->assertStatus(200);
        $eventItem = collect($discoveryRes->json('data'))->firstWhere('id', $event->id);
        $this->assertNotNull($eventItem);
        $this->assertEquals(10, $eventItem['remaining_capacity']);
        $this->assertTrue($eventItem['is_registration_open']);

        // ---------------------------------------------------------
        // 4. STUDENT REGISTERS & RECEIVES DIGITAL TICKET
        // ---------------------------------------------------------
        $regResponse = $this->withHeader('Authorization', 'Bearer '.$studentToken)
            ->postJson("/api/v1/events/{$event->id}/registration");

        $regResponse->assertStatus(201)
            ->assertJson([
                'message' => 'Registration confirmed and ticket issued.',
            ]);

        $qrPayload = $regResponse->json('qr_payload');
        $ticketPublicId = $regResponse->json('ticket.public_id');
        $ticketId = $regResponse->json('ticket.id');

        $this->assertStringStartsWith('ingress:v1:ING-', $qrPayload);

        // Invariant: Confirmed registrations <= capacity
        $this->assertEquals(1, $event->fresh()->getConfirmedRegistrationsCount());

        // Invariant: Zero student PII inside QR payload
        $this->assertStringNotContainsString('Ron', $qrPayload);
        $this->assertStringNotContainsString('a23-35524@student.mseuf.edu.ph', $qrPayload);
        $this->assertStringNotContainsString('A23-35524', $qrPayload);

        // Student checks ticket details via mobile API
        $ticketRes = $this->withHeader('Authorization', 'Bearer '.$studentToken)
            ->getJson("/api/v1/tickets/{$ticketId}");
        $ticketRes->assertStatus(200)
            ->assertJson([
                'ticket' => [
                    'public_id' => $ticketPublicId,
                    'status' => 'active',
                    'qr_payload' => $qrPayload,
                ],
            ]);

        // ---------------------------------------------------------
        // 5. STAFF GATE A LOGS IN & ADMITS TICKET
        // ---------------------------------------------------------
        $staffALoginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff.gate_a@mseuf.edu.ph',
            'password' => 'password',
            'device_name' => 'Gate-A-Scanner-Device',
        ]);
        $staffALoginRes->assertStatus(200);
        $staffAToken = $staffALoginRes->json('token');
        $this->actingAs($staffGateA, 'sanctum');

        // Staff Alex inspects assigned events
        $staffAEvents = $this->withHeader('Authorization', 'Bearer '.$staffAToken)
            ->getJson('/api/v1/staff/events');
        $staffAEvents->assertStatus(200);
        $assignedEvent = collect($staffAEvents->json('events'))->firstWhere('id', $event->id);
        $this->assertNotNull($assignedEvent);

        // Staff Alex scans ticket at Gate A
        $clientScanId1 = (string) Str::uuid();
        $scanGateARes = $this->withHeader('Authorization', 'Bearer '.$staffAToken)
            ->postJson("/api/v1/staff/events/{$event->id}/admissions", [
                'access_point_id' => $gateA->id,
                'qr_payload' => $qrPayload,
                'client_scan_id' => $clientScanId1,
            ]);

        $scanGateARes->assertStatus(200)
            ->assertJson([
                'status' => 'admitted',
                'message' => 'Ticket Valid',
                'admission' => [
                    'access_point' => 'Gate A',
                    'scanned_by' => 'Staff Alex Rivera',
                ],
                'ticket' => [
                    'public_id' => $ticketPublicId,
                    'attendee_name' => 'Ron Vincent Cada',
                    'student_id' => 'A23-35524',
                ],
            ]);

        // Verify admission is transactionally recorded
        $this->assertDatabaseHas('admissions', [
            'client_scan_id' => $clientScanId1,
            'access_point_id' => $gateA->id,
        ]);

        // ---------------------------------------------------------
        // 6. STAFF GATE B SCANS SAME TICKET -> ANTI-PASSBACK BLOCKS
        // ---------------------------------------------------------
        $staffBLoginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'staff.gate_b@mseuf.edu.ph',
            'password' => 'password',
            'device_name' => 'Gate-B-Scanner-Device',
        ]);
        $staffBLoginRes->assertStatus(200);
        $staffBToken = $staffBLoginRes->json('token');
        $this->actingAs($staffGateB, 'sanctum');

        // Staff Bea at Gate B immediately scans the exact same ticket
        $clientScanId2 = (string) Str::uuid();
        $scanGateBRes = $this->withHeader('Authorization', 'Bearer '.$staffBToken)
            ->postJson("/api/v1/staff/events/{$event->id}/admissions", [
                'access_point_id' => $gateB->id,
                'qr_payload' => $qrPayload,
                'client_scan_id' => $clientScanId2,
            ]);

        $scanGateBRes->assertStatus(409)
            ->assertJson([
                'status' => 'already_admitted',
                'message' => 'Already Checked In',
                'previous_admission' => [
                    'access_point' => 'Gate A',
                    'scanned_by' => 'Staff Alex Rivera',
                ],
            ]);

        $this->assertEquals(1, Admission::whereHas('ticket', fn($q) => $q->where('public_id', $ticketPublicId))->count());

        // ---------------------------------------------------------
        // 7. IDEMPOTENT RETRY ON GATE A (NETWORK RE-TRANSMISSION)
        // ---------------------------------------------------------
        $this->actingAs($staffGateA, 'sanctum');
        $retryRes = $this->withHeader('Authorization', 'Bearer '.$staffAToken)
            ->postJson("/api/v1/staff/events/{$event->id}/admissions", [
                'access_point_id' => $gateA->id,
                'qr_payload' => $qrPayload,
                'client_scan_id' => $clientScanId1, // Same client scan ID
            ]);

        $retryRes->assertStatus(200)
            ->assertJson([
                'status' => 'admitted',
                'message' => 'Ticket Valid',
            ]);

        $this->assertEquals(1, Admission::where('client_scan_id', $clientScanId1)->count());

        // ---------------------------------------------------------
        // 8. ORGANIZER DASHBOARD REFLECTS REAL-TIME ADMISSION
        // ---------------------------------------------------------
        $this->actingAs($organizer);

        $attendanceViewRes = $this->get("/organizer/events/{$event->id}/attendance");
        $attendanceViewRes->assertStatus(200);
        $attendanceViewRes->assertSee('Ron Vincent Cada');
        $attendanceViewRes->assertSee('Gate A');
        $attendanceViewRes->assertSee('Entry');

        // Check real-time JSON polling feed
        $feedRes = $this->getJson("/organizer/events/{$event->id}/attendance/feed");
        $feedRes->assertStatus(200)
            ->assertJson([
                'stats' => [
                    'total_capacity' => 10,
                    'registered_count' => 1,
                    'checked_in_count' => 1,
                    'not_arrived_count' => 0,
                    'attendance_percentage' => 100,
                ],
            ]);

        $recentAdmissions = $feedRes->json('recent_admissions');
        $this->assertNotEmpty($recentAdmissions);
        $this->assertEquals('Ron Vincent Cada', $recentAdmissions[0]['attendee_name']);
        $this->assertEquals('Gate A', $recentAdmissions[0]['access_point']);
    }
}
