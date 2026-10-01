<?php

namespace Tests\Feature;

use App\Actions\AdmitTicket;
use App\Actions\RegisterForEvent;
use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $organizerA;

    private User $organizerB;

    private User $student;

    private Event $event;

    private AccessPoint $gateA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizerA = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->organizerB = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->student = User::factory()->create([
            'name' => 'Carlo Mendoza',
            'email' => 'carlo@mseuf.edu.ph',
            'student_id' => '2024-00123',
            'role' => User::ROLE_STUDENT,
        ]);

        $venue = Venue::create([
            'name' => 'MSEUF Gymnasium',
            'code' => 'GYM',
            'location' => 'Main Campus',
            'default_capacity' => 1000,
        ]);

        $this->event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $venue->id,
            'title' => 'Flagship Conference',
            'slug' => 'flagship-conference',
            'capacity' => 50,
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
    }

    public function test_organizer_can_view_attendance_dashboard(): void
    {
        // Register student and admit them
        $regAction = new RegisterForEvent;
        $regResult = $regAction->execute($this->student, $this->event->id);

        $admitAction = new AdmitTicket;
        $admitAction->execute(
            staff: $this->organizerA,
            eventId: $this->event->id,
            accessPointId: $this->gateA->id,
            qrPayload: $regResult['qr_payload'],
            clientScanId: (string) Str::uuid()
        );

        $response = $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}/attendance");

        $response->assertStatus(200);
        $response->assertSee('Event Attendance: Flagship Conference');
        $response->assertSee('Carlo Mendoza');
        $response->assertSee('Gate A - West Wing');
        $response->assertSee('Admitted');
    }

    public function test_attendance_feed_returns_accurate_realtime_metrics(): void
    {
        $regAction = new RegisterForEvent;
        $regResult = $regAction->execute($this->student, $this->event->id);

        $admitAction = new AdmitTicket;
        $admitAction->execute(
            staff: $this->organizerA,
            eventId: $this->event->id,
            accessPointId: $this->gateA->id,
            qrPayload: $regResult['qr_payload'],
            clientScanId: (string) Str::uuid()
        );

        $response = $this->actingAs($this->organizerA)
            ->getJson("/organizer/events/{$this->event->id}/attendance/feed");

        $response->assertStatus(200)
            ->assertJson([
                'stats' => [
                    'total_capacity' => 50,
                    'registered_count' => 1,
                    'checked_in_count' => 1,
                    'not_arrived_count' => 0,
                    'attendance_percentage' => 100,
                ],
                'gate_stats' => [
                    [
                        'name' => 'Gate A - West Wing',
                        'code' => 'GATE-A',
                        'count' => 1,
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('recent_admissions'));
        $this->assertEquals('Carlo Mendoza', $response->json('recent_admissions.0.attendee_name'));
    }

    public function test_organizer_cannot_view_another_organizers_attendance(): void
    {
        $response = $this->actingAs($this->organizerB)
            ->get("/organizer/events/{$this->event->id}/attendance");

        $response->assertStatus(403);
    }

    public function test_student_cannot_access_organizer_attendance(): void
    {
        $response = $this->actingAs($this->student)
            ->get("/organizer/events/{$this->event->id}/attendance");

        $response->assertRedirect(route('login'));
    }

    public function test_superseded_admission_is_not_reported_after_reregistration(): void
    {
        $admitAction = new AdmitTicket;

        // 1. Register, then admit at Gate A.
        $firstRegistration = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $oldQrPayload = $firstRegistration->json('qr_payload');

        $admitAction->execute(
            staff: $this->organizerA,
            eventId: $this->event->id,
            accessPointId: $this->gateA->id,
            qrPayload: $oldQrPayload,
            clientScanId: (string) Str::uuid()
        );

        $this->assertEquals(1, Admission::count());

        // 2. Cancel and register again: the same ticket row is reused with a bumped credential.
        $this->actingAs($this->student, 'sanctum')
            ->deleteJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(200);

        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(201);

        // 3. The retired admission is retained for audit but is no longer current state.
        $this->assertEquals(1, Admission::count(), 'audit history is preserved');
        $this->assertEquals(0, Admission::forCurrentCredential()->count(), 'no current-credential admission exists');

        // 4. Every organizer surface agrees on the current state.
        $feed = $this->actingAs($this->organizerA)
            ->getJson("/organizer/events/{$this->event->id}/attendance/feed");

        $feed->assertStatus(200)
            ->assertJson([
                'stats' => [
                    'registered_count' => 1,
                    'checked_in_count' => 0,
                    'not_arrived_count' => 1,
                    'attendance_percentage' => 0,
                ],
            ]);

        $this->assertEmpty($feed->json('recent_admissions'));

        $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}")
            ->assertStatus(200);

        $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}/attendance")
            ->assertStatus(200);
    }
}
