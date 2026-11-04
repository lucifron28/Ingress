<?php

namespace Tests\Feature;

use App\Actions\AdmitTicket;
use App\Actions\RegisterForEvent;
use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
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
        $response->assertSee('Entry');
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

    public function test_attendance_search_finds_a_walk_in_by_student_id(): void
    {
        Registration::create([
            'event_id' => $this->event->id, 'user_id' => null, 'attendee_type' => 'walk_in',
            'walk_in_kind' => 'student', 'guest_name' => 'Walk In Student', 'guest_email' => 'walkin@example.test',
            'guest_student_id' => 'WI-45678', 'status' => Registration::STATUS_CONFIRMED,
        ]);
        Registration::create([
            'event_id' => $this->event->id, 'user_id' => null, 'attendee_type' => 'walk_in',
            'walk_in_kind' => 'guest', 'guest_name' => 'Other Guest', 'guest_email' => 'other@example.test',
            'status' => Registration::STATUS_CONFIRMED,
        ]);
        $this->actingAs($this->organizerA)->get("/organizer/events/{$this->event->id}/attendance?search=wi-456")
            ->assertOk()->assertSee('Walk In Student')->assertDontSee('Other Guest');
    }

    public function test_attendance_page_uses_safe_dom_rendering_and_refresh_feed_trigger(): void
    {
        $response = $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}/attendance");

        $response->assertStatus(200);

        // Verify the manual 'Refresh Feed' button with onclick handler exists
        $response->assertSee('Refresh Feed');
        $response->assertSee('onclick="fetchLiveData()"', false);

        // Verify the 5-second automatic polling interval is configured
        $response->assertSee('setInterval(fetchLiveData, 5000)', false);

        // Verify AbortController timeout mechanism and isFetching concurrency lock exist
        $response->assertSee('const requestTimeoutMs = typeof INGRESS_POLL_TIMEOUT_MS !== \'undefined\' ? INGRESS_POLL_TIMEOUT_MS : 4500;', false);
        $response->assertSee('const controller = new AbortController();', false);
        $response->assertSee('const timeoutId = setTimeout(() => controller.abort(), requestTimeoutMs);', false);
        $response->assertSee('signal: controller.signal', false);
        $response->assertSee('clearTimeout(timeoutId);', false);
        $response->assertSee('let isFetching = false;', false);
        $response->assertSee('if (isFetching) return;', false);

        // Verify standardized access point terminology
        $response->assertSee('Movements by Access Point');
        $response->assertSee('No access points configured.');
        $response->assertSee('<th>Access Point</th>', false);

        // Verify live connection indicator elements and transition handlers exist
        $response->assertSee('id="live-indicator"', false);
        $response->assertSee('id="live-indicator-dot"', false);
        $response->assertSee('id="live-indicator-text"', false);
        $response->assertSee('function setConnectionLive', false);
        $response->assertSee('function setConnectionLost', false);
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

        // Verify server-controlled fields are assigned via safe DOM text APIs rather than unsafe HTML interpolation
        $this->assertStringContainsString('codeSpan.textContent = gate.code;', $html);
        $this->assertStringContainsString('nameStrong.textContent = gate.name;', $html);
        $this->assertStringContainsString('countDiv.textContent = gate.count;', $html);
        $this->assertStringContainsString('tdTime.textContent = adm.admitted_at;', $html);
        $this->assertStringContainsString('strongName.textContent = adm.attendee_name;', $html);
        $this->assertStringContainsString('tdId.textContent = adm.student_id;', $html);
        $this->assertStringContainsString('gateBadge.textContent = adm.access_point;', $html);
        $this->assertStringContainsString('tdStaff.textContent = adm.scanned_by;', $html);
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

        $feedResponse = $this->actingAs($this->organizerA)
            ->getJson("/organizer/events/{$this->event->id}/attendance/feed");
        $feedResponse->assertStatus(200);
        $feedData = $feedResponse->json();

        // 3. Extract the <script> block containing fetchLiveData()
        preg_match('/<script>([\s\S]*?)<\/script>/', $pageResponse->getContent(), $matches);
        $this->assertNotEmpty($matches[1], 'The attendance view must include the client-side refresh script.');

        // 4. Execute the live-refresh script against the real feed data
        $results = $this->executeDashboardScript($matches[1], [
            ['ok' => true, 'data' => $feedData],
        ]);
        $domUpdates = $results[0];

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

    public function test_empty_live_feed_clears_stale_admission_rows(): void
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

        $pageResponse = $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}/attendance");
        $initialFeed = $this->actingAs($this->organizerA)
            ->getJson("/organizer/events/{$this->event->id}/attendance/feed")->json();

        preg_match('/<script>([\s\S]*?)<\/script>/', $pageResponse->getContent(), $matches);
        $this->assertNotEmpty($matches[1]);

        // Simulated empty feed (e.g. registration cancelled or no active admissions)
        $emptyFeed = $initialFeed;
        $emptyFeed['recent_admissions'] = [];
        $emptyFeed['stats']['checked_in_count'] = 0;

        // Execute Step 1 (active admission) then Step 2 (empty feed)
        $results = $this->executeDashboardScript($matches[1], [
            ['ok' => true, 'data' => $initialFeed],
            ['ok' => true, 'data' => $emptyFeed],
        ]);

        // Step 1: Active admission rendered
        $step1 = $results[0];
        $this->assertStringContainsString('Ron Vincent Cada', $step1['recentHtml']);

        // Step 2: Empty feed clears stale rows and displays empty state
        $step2 = $results[1];
        $this->assertStringNotContainsString('Ron Vincent Cada', $step2['recentHtml']);
        $this->assertEquals(1, $step2['recentRowCount']);
        $this->assertEquals('no-scans-row', $step2['firstRowId']);
        $this->assertStringContainsString('No movements recorded yet. Turnstile scanners are ready.', $step2['recentTextContent']);
    }

    public function test_server_provided_values_are_rendered_as_text_and_prevent_html_injection(): void
    {
        $pageResponse = $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}/attendance");
        preg_match('/<script>([\s\S]*?)<\/script>/', $pageResponse->getContent(), $matches);
        $this->assertNotEmpty($matches[1]);

        $hostileFeed = [
            'stats' => [
                'total_capacity' => 50,
                'registered_count' => 1,
                'checked_in_count' => 1,
                'not_arrived_count' => 0,
                'attendance_percentage' => 100,
            ],
            'gate_stats' => [
                [
                    'id' => 1,
                    'name' => '<iframe src="evil.com"></iframe>',
                    'code' => '<svg onload=alert(2)>',
                    'count' => 1,
                ],
            ],
            'recent_admissions' => [
                [
                    'id' => 1,
                    'admitted_at' => '10:30:00 AM',
                    'attendee_name' => '<img src=x onerror=alert(1)>',
                    'student_id' => 'A23-35524',
                    'access_point' => '<script>alert(1)</script>',
                    'scanned_by' => '<b>Fake Staff</b>',
                ],
            ],
        ];

        $results = $this->executeDashboardScript($matches[1], [
            ['ok' => true, 'data' => $hostileFeed],
        ]);
        $dom = $results[0];

        // The DOM MUST NOT parse hostile values into actual HTML element nodes
        $this->assertEquals(0, $dom['imgCountInRecent'], 'Hostile <img> must not be instantiated in DOM');
        $this->assertEquals(0, $dom['scriptCountInRecent'], 'Hostile <script> must not be instantiated in DOM');
        $this->assertEquals(0, $dom['bCountInRecent'], 'Hostile <b> must not be instantiated in DOM');
        $this->assertEquals(0, $dom['svgCountInGate'], 'Hostile <svg> must not be instantiated in DOM');
        $this->assertEquals(0, $dom['iframeCountInGate'], 'Hostile <iframe> must not be instantiated in DOM');

        // The values MUST be rendered safely as text content
        $this->assertStringContainsString('<img src=x onerror=alert(1)>', $dom['recentTextContent']);
        $this->assertStringContainsString('<script>alert(1)</script>', $dom['recentTextContent']);
        $this->assertStringContainsString('<b>Fake Staff</b>', $dom['recentTextContent']);

        // Serialized innerHTML must escape HTML special characters
        $this->assertStringContainsString('&lt;img', $dom['recentHtml']);
        $this->assertStringNotContainsString('<img src=x', $dom['recentHtml']);
    }

    public function test_connection_state_indicator_and_last_updated_behavior(): void
    {
        $pageResponse = $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}/attendance");
        preg_match('/<script>([\s\S]*?)<\/script>/', $pageResponse->getContent(), $matches);
        $this->assertNotEmpty($matches[1]);

        $feedData = [
            'stats' => [
                'total_capacity' => 50,
                'registered_count' => 1,
                'checked_in_count' => 1,
                'not_arrived_count' => 0,
                'attendance_percentage' => 100,
            ],
            'gate_stats' => [
                ['id' => 1, 'name' => 'Gate A', 'code' => 'GATE-A', 'count' => 1],
            ],
            'recent_admissions' => [
                [
                    'id' => 1,
                    'admitted_at' => '10:30:00 AM',
                    'attendee_name' => 'Ron Vincent Cada',
                    'student_id' => 'A23-35524',
                    'access_point' => 'Gate A',
                    'scanned_by' => 'Staff Alex Rivera',
                ],
            ],
        ];

        // 3 steps: 1. Successful fetch -> 2. Failed fetch (500) -> 3. Recovery fetch
        $results = $this->executeDashboardScript($matches[1], [
            ['ok' => true, 'data' => $feedData],
            ['ok' => false, 'status' => 500],
            ['ok' => true, 'data' => $feedData],
        ]);

        // Step 1: Successful initial poll
        $step1 = $results[0];
        $this->assertEquals('LAN LIVE', $step1['liveIndicatorText']);
        $this->assertEquals('#10b981', $step1['dotBg']);
        $this->assertEquals('#ecfdf5', $step1['liveIndicatorBg']);
        $this->assertStringStartsWith('Updated ', $step1['feedLastUpdated']);

        // Step 2: Server failure -> CONNECTION LOST, previous data retained
        $step2 = $results[1];
        $this->assertEquals('CONNECTION LOST', $step2['liveIndicatorText']);
        $this->assertEquals('#ef4444', $step2['dotBg']);
        $this->assertEquals('#fef2f2', $step2['liveIndicatorBg']);
        $this->assertStringStartsWith('Last successful update ', $step2['feedLastUpdated']);
        // Crucial requirement: last known attendance data remains visible on the dashboard
        $this->assertStringContainsString('Ron Vincent Cada', $step2['recentHtml']);
        $this->assertEquals(1, $step2['recentRowCount']);
        $this->assertStringContainsString('GATE-A', $step2['gateHtml']);

        // Step 3: Recovery -> LAN LIVE restored
        $step3 = $results[2];
        $this->assertEquals('LAN LIVE', $step3['liveIndicatorText']);
        $this->assertEquals('#10b981', $step3['dotBg']);
        $this->assertEquals('#ecfdf5', $step3['liveIndicatorBg']);
        $this->assertStringStartsWith('Updated ', $step3['feedLastUpdated']);
    }

    public function test_polling_timeout_and_abort_transitions_to_connection_lost_and_recovers(): void
    {
        $pageResponse = $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}/attendance");
        preg_match('/<script>([\s\S]*?)<\/script>/', $pageResponse->getContent(), $matches);
        $this->assertNotEmpty($matches[1]);

        $feedData = [
            'stats' => [
                'total_capacity' => 50,
                'registered_count' => 1,
                'checked_in_count' => 1,
                'not_arrived_count' => 0,
                'attendance_percentage' => 100,
            ],
            'gate_stats' => [
                ['id' => 1, 'name' => 'Entrance 1', 'code' => 'ENTRANCE-1', 'count' => 1],
            ],
            'recent_admissions' => [
                [
                    'id' => 1,
                    'admitted_at' => '10:30:00 AM',
                    'attendee_name' => 'Ron Vincent Cada',
                    'student_id' => 'A23-35524',
                    'access_point' => 'Entrance 1',
                    'scanned_by' => 'Staff Alex Rivera',
                ],
            ],
        ];

        // Step 1: Successful initial poll
        // Step 2: Genuinely hanging network poll that waits for AbortController timer (30ms) to call controller.abort()
        // Step 3: Network recovery
        $results = $this->executeDashboardScript($matches[1], [
            ['ok' => true, 'data' => $feedData],
            ['hang' => true],
            ['ok' => true, 'data' => $feedData],
        ], pollTimeoutMs: 30);

        // Step 1: Healthy LAN LIVE state
        $step1 = $results[0];
        $this->assertEquals('LAN LIVE', $step1['liveIndicatorText']);
        $this->assertEquals('#10b981', $step1['dotBg']);
        $this->assertStringStartsWith('Updated ', $step1['feedLastUpdated']);

        // Step 2: Real AbortController timer fired -> signal aborted -> CONNECTION LOST
        $step2 = $results[1];
        $this->assertTrue($step2['signalAborted'], 'AbortSignal must be genuinely aborted by controller.abort()');
        $this->assertEquals('CONNECTION LOST', $step2['liveIndicatorText']);
        $this->assertEquals('#ef4444', $step2['dotBg']);
        $this->assertEquals('#fef2f2', $step2['liveIndicatorBg']);
        $this->assertStringStartsWith('Last successful update ', $step2['feedLastUpdated']);
        $this->assertStringContainsString('Ron Vincent Cada', $step2['recentHtml']);
        $this->assertStringContainsString('ENTRANCE-1', $step2['gateHtml']);
        // Step 3: Recovery restores LAN LIVE and advances timestamp
        $step3 = $results[2];
        $this->assertEquals('LAN LIVE', $step3['liveIndicatorText']);
        $this->assertEquals('#10b981', $step3['dotBg']);
        $this->assertEquals('#ecfdf5', $step3['liveIndicatorBg']);
        $this->assertStringStartsWith('Updated ', $step3['feedLastUpdated']);
    }

    public function test_overlapping_polls_are_prevented_by_fetching_lock(): void
    {
        $pageResponse = $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}/attendance");
        preg_match('/<script>([\s\S]*?)<\/script>/', $pageResponse->getContent(), $matches);
        $this->assertNotEmpty($matches[1]);

        // Execute concurrency probe step: initiates delayed poll then attempts overlapping call
        $results = $this->executeDashboardScript($matches[1], [
            ['testConcurrent' => true],
        ]);

        $probe = $results[0];
        $this->assertTrue($probe['inFlight'], 'isFetching must be true while a network request is pending');
        $this->assertEquals(1, $probe['callCountDuringFlight'], 'Overlapping poll must return early and not issue a second fetch');
        $this->assertFalse($probe['finishedFetching'], 'isFetching must reset to false after request completion');
    }

    /**
     * Helper to run the attendance view's fetchLiveData() script in Node.js
     * with a lightweight DOM mock supporting createElement, textContent, appendChild, style, etc.
     *
     * @param  array<int, array{ok?: bool, status?: int, data?: mixed, throws?: bool}>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function executeDashboardScript(string $scriptCode, array $steps, int $pollTimeoutMs = 30): array
    {
        $nodeRunner = <<<'JS'
        const fs = require('fs');
        const payload = JSON.parse(fs.readFileSync(0, 'utf-8'));
        const scriptCode = payload.script;
        const steps = payload.steps;
        const pollTimeoutMs = payload.pollTimeoutMs || 30;

        function createElement(tag) {
            return {
                tagName: tag.toUpperCase(),
                children: [],
                style: {},
                className: '',
                id: '',
                colSpan: 1,
                _textContent: '',
                get textContent() {
                    if (this.children.length === 0) return this._textContent;
                    return this.children.map(c => c.textContent).join('');
                },
                set textContent(val) {
                    this.children = [];
                    this._textContent = String(val);
                },
                get innerText() { return this.textContent; },
                set innerText(val) { this.textContent = val; },
                appendChild(child) {
                    this.children.push(child);
                    return child;
                },
                getElementsByTagName(name) {
                    const res = [];
                    const upper = name.toUpperCase();
                    for (const child of this.children) {
                        if (child.tagName === upper) res.push(child);
                        if (child.getElementsByTagName) res.push(...child.getElementsByTagName(name));
                    }
                    return res;
                },
                get innerHTML() {
                    if (this.children.length === 0) {
                        return this._textContent
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;');
                    }
                    return this.children.map(c => {
                        let attrs = '';
                        if (c.className) attrs += ` class="${c.className}"`;
                        if (c.id) attrs += ` id="${c.id}"`;
                        if (c.colSpan > 1) attrs += ` colspan="${c.colSpan}"`;
                        return `<${c.tagName.toLowerCase()}${attrs}>${c.innerHTML}</${c.tagName.toLowerCase()}>`;
                    }).join('');
                },
                set innerHTML(val) {
                    this.children = [];
                    this._textContent = val;
                }
            };
        }

        const elements = {};
        const defaultIds = [
            'stat-capacity', 'stat-registered', 'stat-checked-in', 'stat-not-arrived', 'stat-percentage',
            'progress-bar', 'gate-container', 'recent-admissions-tbody', 'feed-last-updated',
            'live-indicator', 'live-indicator-dot', 'live-indicator-text'
        ];
        defaultIds.forEach(id => {
            elements[id] = createElement('div');
            elements[id].id = id;
        });

        const document = {
            getElementById: (id) => elements[id] || null,
            createElement: createElement
        };

        let currentStepResponse = null;
        let fetchCallCount = 0;
        let resolveDelayedPromise = null;
        let lastObservedSignalAborted = false;
        const fetch = async (url, options) => {
            fetchCallCount++;
            if (currentStepResponse.throws) {
                throw new Error('Network failure');
            }
            if (currentStepResponse.delayed) {
                return new Promise((resolve) => {
                    resolveDelayedPromise = () => resolve({
                        ok: true,
                        status: 200,
                        json: async () => ({})
                    });
                });
            }
            if (currentStepResponse.hang) {
                return new Promise((resolve, reject) => {
                    if (options?.signal) {
                        if (options.signal.aborted) {
                            lastObservedSignalAborted = true;
                            const err = new Error('The operation was aborted');
                            err.name = 'AbortError';
                            return reject(err);
                        }
                        options.signal.addEventListener('abort', () => {
                            lastObservedSignalAborted = options.signal.aborted;
                            const err = new Error('The operation was aborted');
                            err.name = 'AbortError';
                            reject(err);
                        });
                    }
                });
            }
            return {
                ok: currentStepResponse.ok ?? true,
                status: currentStepResponse.status ?? 200,
                json: async () => currentStepResponse.data ?? {}
            };
        };
        const setInterval = () => {};

        const fn = new Function('document', 'fetch', 'setInterval', 'INGRESS_POLL_TIMEOUT_MS', `
            ${scriptCode}
            return {
                fetchLiveData,
                getIsFetching: () => isFetching,
                getFetchCount: () => fetchCallCount
            };
        `);
        const dashboard = fn(document, fetch, setInterval, pollTimeoutMs);
        const fetchLiveData = dashboard.fetchLiveData;
        (async () => {
            const results = [];
            for (const step of steps) {
                currentStepResponse = step;
                if (step.testConcurrent) {
                    currentStepResponse = { delayed: true };
                    const p1 = fetchLiveData();
                    const inFlight = dashboard.getIsFetching();
                    const p2 = fetchLiveData();
                    const callCountDuringFlight = dashboard.getFetchCount();
                    if (resolveDelayedPromise) resolveDelayedPromise();
                    await Promise.allSettled([p1, p2]);
                    const finishedFetching = dashboard.getIsFetching();
                    results.push({
                        inFlight,
                        callCountDuringFlight,
                        finishedFetching,
                    });
                    continue;
                }
                await fetchLiveData();
                results.push({
                    gateHtml: elements['gate-container'].innerHTML,
                    recentHtml: elements['recent-admissions-tbody'].innerHTML,
                    recentRowCount: elements['recent-admissions-tbody'].children.length,
                    firstRowId: elements['recent-admissions-tbody'].children[0]?.id || '',
                    recentTextContent: elements['recent-admissions-tbody'].textContent,
                    imgCountInRecent: elements['recent-admissions-tbody'].getElementsByTagName('img').length,
                    scriptCountInRecent: elements['recent-admissions-tbody'].getElementsByTagName('script').length,
                    bCountInRecent: elements['recent-admissions-tbody'].getElementsByTagName('b').length,
                    svgCountInGate: elements['gate-container'].getElementsByTagName('svg').length,
                    iframeCountInGate: elements['gate-container'].getElementsByTagName('iframe').length,
                    capacity: elements['stat-capacity'].textContent,
                    registered: elements['stat-registered'].textContent,
                    checkedIn: elements['stat-checked-in'].textContent,
                    liveIndicatorText: elements['live-indicator-text'].textContent,
                    liveIndicatorColor: elements['live-indicator'].style.color || '',
                    liveIndicatorBg: elements['live-indicator'].style.background || '',
                    dotBg: elements['live-indicator-dot'].style.background || '',
                    feedLastUpdated: elements['feed-last-updated'].textContent,
                    signalAborted: lastObservedSignalAborted
                });
            }
            console.log(JSON.stringify(results));
        })().catch(err => {
            console.error(err);
            process.exit(1);
        });
        JS;

        $process = new Process(['node', '-e', $nodeRunner]);
        $process->setInput(json_encode([
            'script' => $scriptCode,
            'steps' => $steps,
            'pollTimeoutMs' => $pollTimeoutMs,
        ]));
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'Node.js failed to execute dashboard script: '.$process->getErrorOutput());

        return json_decode($process->getOutput(), true);
    }

    public function test_student_cannot_access_organizer_attendance(): void
    {
        $response = $this->actingAs($this->student)
            ->get("/organizer/events/{$this->event->id}/attendance");

        $response->assertRedirect(route('login'));
    }

    public function test_credential_rotation_does_not_reset_checked_in_state(): void
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

        // 2. Controlled credential rotation (e.g. administrative reissue): credential_version is bumped.
        $ticket = Ticket::whereHas('registration', fn ($q) => $q->where('user_id', $this->student->id))->first();
        $newSecret = bin2hex(random_bytes(32));
        $ticket->update([
            'qr_hash' => hash('sha256', $newSecret),
            'encrypted_secret' => Crypt::encryptString($newSecret),
            'credential_version' => 2,
        ]);

        // 3. Admission history is preserved and attendee remains checked in.
        $this->assertEquals(1, Admission::count(), 'audit history is preserved');

        // 4. Organizer surfaces agree: attendee remains checked in despite credential rotation.
        $feed = $this->actingAs($this->organizerA)
            ->getJson("/organizer/events/{$this->event->id}/attendance/feed");

        $feed->assertStatus(200)
            ->assertJson([
                'stats' => [
                    'registered_count' => 1,
                    'checked_in_count' => 1,
                    'not_arrived_count' => 0,
                    'attendance_percentage' => 100.0,
                ],
            ]);

        $this->assertCount(1, $feed->json('recent_admissions'));
        $this->assertEquals(1, collect($feed->json('gate_stats'))->sum('count'));

        $this->actingAs($this->organizerA)
            ->get("/organizer/events/{$this->event->id}")
            ->assertStatus(200);
    }

    public function test_administratively_invalidated_registration_is_not_counted_as_checked_in(): void
    {
        $admitAction = new AdmitTicket;

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

        // Administratively revoking the registration and ticket after admission
        // must not leave a checked-in count that exceeds the registered count.
        $ticket = Ticket::whereHas('registration', fn ($q) => $q->where('user_id', $this->student->id))->first();
        $ticket->registration->update([
            'status' => Registration::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
        $ticket->update([
            'status' => Ticket::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
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
