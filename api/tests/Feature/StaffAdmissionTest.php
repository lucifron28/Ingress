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

class StaffAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $organizer;
    private User $staffGateA;
    private User $staffGateB;
    private User $student;
    private Venue $venue;
    private Event $event;
    private AccessPoint $gateA;
    private AccessPoint $gateB;
    private Ticket $ticket;
    private string $validQrPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizer = User::factory()->create([
            'name' => 'Event Organizer',
            'email' => 'organizer@mseuf.edu.ph',
            'role' => User::ROLE_ORGANIZER,
        ]);

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

        $this->student = User::factory()->create([
            'name' => 'Ron Vincent Cada',
            'email' => 'a23-35524@student.mseuf.edu.ph',
            'student_id' => 'A23-35524',
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
            'title' => 'MSEUF Tech Expo 2026',
            'slug' => 'mseuf-tech-expo-2026',
            'capacity' => 100,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(2),
            'ends_at' => Carbon::now()->addDays(2)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
        ]);

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

        $action = new RegisterForEvent;
        $result = $action->execute($this->student, $this->event->id);
        $this->validQrPayload = $result['qr_payload'];
        $this->ticket = $result['ticket'];
    }

    public function test_preview_succeeds_then_staff_assignment_removed_then_confirm_is_rejected_403_no_admission_row(): void
    {
        // 1. Preview succeeds
        $previewResponse = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions/preview", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
            ]);

        $previewResponse->assertStatus(200)
            ->assertJson(['status' => 'valid']);

        // 2. Staff assignment is removed
        EventStaff::where('event_id', $this->event->id)
            ->where('user_id', $this->staffGateA->id)
            ->delete();

        // 3. Confirm admission attempt
        $scanId = (string) Str::uuid();
        $confirmResponse = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId,
            ]);

        // 4. Confirm is rejected with 403 unauthorized_staff
        $confirmResponse->assertStatus(403)
            ->assertJson([
                'status' => 'unauthorized_staff',
            ]);

        // 5. Invariant: No admission row created
        $this->assertEquals(0, Admission::where('client_scan_id', $scanId)->count());
        $this->assertEquals(0, Admission::where('ticket_id', $this->ticket->id)->count());
    }

    public function test_preview_succeeds_then_gate_disabled_then_confirm_is_rejected_no_admission_row(): void
    {
        // 1. Preview succeeds
        $previewResponse = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions/preview", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
            ]);

        $previewResponse->assertStatus(200)
            ->assertJson(['status' => 'valid']);

        // 2. Gate is disabled
        $this->gateA->update(['is_active' => false]);

        // 3. Confirm admission attempt
        $scanId = (string) Str::uuid();
        $confirmResponse = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId,
            ]);

        // 4. Confirm is rejected with 422 invalid_gate
        $confirmResponse->assertStatus(422)
            ->assertJson([
                'status' => 'invalid_gate',
            ]);

        // 5. Invariant: No admission row created
        $this->assertEquals(0, Admission::where('client_scan_id', $scanId)->count());
        $this->assertEquals(0, Admission::where('ticket_id', $this->ticket->id)->count());
    }

    public function test_preview_succeeds_then_staff_gate_changed_then_confirm_is_rejected_no_admission_row(): void
    {
        // 1. Preview succeeds
        $previewResponse = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions/preview", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
            ]);

        $previewResponse->assertStatus(200)
            ->assertJson(['status' => 'valid']);

        // 2. Staff is reassigned to Gate B
        EventStaff::where('event_id', $this->event->id)
            ->where('user_id', $this->staffGateA->id)
            ->update(['access_point_id' => $this->gateB->id]);

        // 3. Confirm admission attempt at Gate A
        $scanId = (string) Str::uuid();
        $confirmResponse = $this->actingAs($this->staffGateA)
            ->postJson("/api/v1/staff/events/{$this->event->id}/admissions", [
                'access_point_id' => $this->gateA->id,
                'qr_payload' => $this->validQrPayload,
                'client_scan_id' => $scanId,
            ]);

        // 4. Confirm is rejected with 403 gate_mismatch
        $confirmResponse->assertStatus(403)
            ->assertJson([
                'status' => 'gate_mismatch',
            ]);

        // 5. Invariant: No admission row created
        $this->assertEquals(0, Admission::where('client_scan_id', $scanId)->count());
        $this->assertEquals(0, Admission::where('ticket_id', $this->ticket->id)->count());
    }

}
