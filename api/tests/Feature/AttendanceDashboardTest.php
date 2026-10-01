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
            'name' => 'Ron Vincent Cada',
            'email' => 'a23-35524@student.mseuf.edu.ph',
            'student_id' => 'A23-35524',
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
        $response->assertSee('Ron Vincent Cada');
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
        $this->assertEquals('Ron Vincent Cada', $response->json('recent_admissions.0.attendee_name'));
    }

    public function test_organizer_cannot_view_another_organizers_attendance(): void
    {
        $response = $this->actingAs($this->organizerB)
            ->get("/organizer/events/{$this->event->id}/attendance");

        $response->assertStatus(403);
    }

    public function test_attendance_page_contains_valid_template_literals_and_refresh_feed_trigger(): void
    {
        $response = $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}/attendance");

        $response->assertStatus(200);

        // Verify the manual 'Refresh Feed' button with onclick handler exists
        $response->assertSee('Refresh Feed');
        $response->assertSee('onclick="fetchLiveData()"', false);

        // Verify the 5-second automatic polling interval is configured
        $response->assertSee('setInterval(fetchLiveData, 5000)', false);

        $html = $response->getContent();

        // Regression assertion: JS template literals MUST NOT be backslash-escaped
        $this->assertStringNotContainsString('\${gate.code}', $html);
        $this->assertStringNotContainsString('\${gate.name}', $html);
        $this->assertStringNotContainsString('\${gate.count}', $html);
        $this->assertStringNotContainsString('\${adm.admitted_at}', $html);
        $this->assertStringNotContainsString('\${adm.attendee_name}', $html);
        $this->assertStringNotContainsString('\${adm.student_id}', $html);
        $this->assertStringNotContainsString('\${adm.access_point}', $html);
        $this->assertStringNotContainsString('\${adm.scanned_by}', $html);

        // Verify proper unescaped JS template literal interpolations are present
        $this->assertStringContainsString('${gate.code}', $html);
        $this->assertStringContainsString('${gate.name}', $html);
        $this->assertStringContainsString('${gate.count}', $html);
        $this->assertStringContainsString('${adm.admitted_at}', $html);
        $this->assertStringContainsString('${adm.attendee_name}', $html);
        $this->assertStringContainsString('${adm.student_id}', $html);
        $this->assertStringContainsString('${adm.access_point}', $html);
        $this->assertStringContainsString('${adm.scanned_by}', $html);
    }

    public function test_live_refresh_renders_real_gate_and_admission_values_without_literal_placeholders(): void
    {
        // 1. Register student and record admission
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

        // 2. Fetch attendance page HTML and live JSON feed
        $pageResponse = $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}/attendance");
        $pageResponse->assertStatus(200);
        $pageHtml = $pageResponse->getContent();

        $feedResponse = $this->actingAs($this->organizerA)
            ->getJson("/organizer/events/{$this->event->id}/attendance/feed");
        $feedResponse->assertStatus(200);
        $feedData = $feedResponse->json();

        // 3. Extract the <script> block containing fetchLiveData()
        preg_match('/<script>([\s\S]*?)<\/script>/', $pageHtml, $matches);
        $this->assertNotEmpty($matches[1], 'The attendance view must include the client-side refresh script.');
        $scriptContent = $matches[1];

        // 4. Execute the live-refresh script against the real feed data using Node.js
        $nodeRunner = <<<'JS'
        const scriptCode = process.argv[1];
        const feedJson = process.argv[2];
        const feedData = JSON.parse(feedJson);

        const elements = {};
        function getEl(id) {
            if (!elements[id]) {
                elements[id] = { innerText: '', innerHTML: '', style: {} };
            }
            return elements[id];
        }

        const document = { getElementById: getEl };
        const fetch = async () => ({
            ok: true,
            json: async () => feedData
        });
        const setInterval = () => {};

        const fn = new Function('document', 'fetch', 'setInterval', `
            return (async () => {
                ${scriptCode}
                await fetchLiveData();
                return {
                    gateHtml: document.getElementById('gate-container').innerHTML,
                    recentHtml: document.getElementById('recent-admissions-tbody').innerHTML,
                    capacity: document.getElementById('stat-capacity').innerText,
                    registered: document.getElementById('stat-registered').innerText,
                    checkedIn: document.getElementById('stat-checked-in').innerText
                };
            })();
        `);

        fn(document, fetch, setInterval).then(res => {
            console.log(JSON.stringify(res));
        }).catch(err => {
            console.error(err);
            process.exit(1);
        });
        JS;

        $process = new \Symfony\Component\Process\Process([
            'node',
            '-e',
            $nodeRunner,
            $scriptContent,
            json_encode($feedData),
        ]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'Node.js failed to execute fetchLiveData: '.$process->getErrorOutput());
        $domUpdates = json_decode($process->getOutput(), true);

        // 5. Verify Throughput by Gate shows real gate code, name, and count
        $gateHtml = $domUpdates['gateHtml'];
        $this->assertStringContainsString('GATE-A', $gateHtml);
        $this->assertStringContainsString('Gate A - West Wing', $gateHtml);
        $this->assertStringContainsString('1', $gateHtml);

        // 6. Verify Recent Admissions shows real attendee values
        $recentHtml = $domUpdates['recentHtml'];
        $this->assertStringContainsString('Ron Vincent Cada', $recentHtml);
        $this->assertStringContainsString('A23-35524', $recentHtml);
        $this->assertStringContainsString('Gate A - West Wing', $recentHtml);

        // 7. Verify NO literal placeholder text exists anywhere in the dynamically generated HTML
        $this->assertStringNotContainsString('${gate.code}', $gateHtml);
        $this->assertStringNotContainsString('${gate.name}', $gateHtml);
        $this->assertStringNotContainsString('${gate.count}', $gateHtml);

        $this->assertStringNotContainsString('${adm.admitted_at}', $recentHtml);
        $this->assertStringNotContainsString('${adm.attendee_name}', $recentHtml);
        $this->assertStringNotContainsString('${adm.student_id}', $recentHtml);
        $this->assertStringNotContainsString('${adm.access_point}', $recentHtml);
        $this->assertStringNotContainsString('${adm.scanned_by}', $recentHtml);
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
    }

    public function test_a_cancelled_registration_stops_counting_as_checked_in(): void
    {
        $admitAction = new AdmitTicket();

        // Register and admit at Gate A.
        $registration = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/events/{$this->event->id}/registration");
        $qrPayload = $registration->json('qr_payload');

        $admitAction->execute(
            staff: $this->organizerA,
            eventId: $this->event->id,
            accessPointId: $this->gateA->id,
            qrPayload: $qrPayload,
            clientScanId: (string) Str::uuid()
        );

        $before = $this->actingAs($this->organizerA)
            ->getJson("/organizer/events/{$this->event->id}/attendance/feed");
        $before->assertJson(['stats' => ['registered_count' => 1, 'checked_in_count' => 1]]);

        // Cancelling after admission must not leave a checked-in count that exceeds
        // the registered count.
        $this->actingAs($this->student, 'sanctum')
            ->deleteJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(200);

        $after = $this->actingAs($this->organizerA)
            ->getJson("/organizer/events/{$this->event->id}/attendance/feed");

        $after->assertStatus(200)
            ->assertJson([
                'stats' => [
                    'registered_count' => 0,
                    'checked_in_count' => 0,
                    'not_arrived_count' => 0,
                    'attendance_percentage' => 0,
                ],
            ]);

        $this->assertEmpty($after->json('recent_admissions'), 'the live feed must agree with the KPI');
        $this->assertEquals(0, collect($after->json('gate_stats'))->sum('count'), 'gate breakdown must agree too');
    }
}
