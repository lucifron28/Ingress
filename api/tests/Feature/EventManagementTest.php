<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Models\Venue;
use App\Support\EventSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $organizerA;

    private User $organizerB;

    private User $student;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizerA = User::factory()->create([
            'email' => 'organizerA@mseuf.edu.ph',
            'role' => User::ROLE_ORGANIZER,
        ]);

        $this->organizerB = User::factory()->create([
            'email' => 'organizerB@mseuf.edu.ph',
            'role' => User::ROLE_ORGANIZER,
        ]);

        $this->student = User::factory()->create([
            'email' => 'student@mseuf.edu.ph',
            'role' => User::ROLE_STUDENT,
        ]);

        $this->venue = Venue::create([
            'name' => 'MSEUF Gymnasium',
            'code' => 'GYM',
            'location' => 'Main Campus',
            'default_capacity' => 1000,
        ]);
    }

    public function test_organizer_can_create_event_with_default_access_points(): void
    {
        $now = Carbon::now();
        $response = $this->actingAs($this->organizerA)
            ->post('/organizer/events', [
                'venue_id' => $this->venue->id,
                'title' => 'C-Sharpening Workshop',
                'description' => 'A workshop on C# and .NET.',
                'capacity' => 45,
                'registration_opens_at' => $now->copy()->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
                'registration_closes_at' => $now->copy()->addDays(5)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
                'starts_at' => $now->copy()->addDays(6)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
                'ends_at' => $now->copy()->addDays(6)->addHours(4)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i'),
            ]);

        $event = Event::where('title', 'C-Sharpening Workshop')->first();
        $this->assertNotNull($event);
        $this->assertEquals(Event::STATUS_DRAFT, $event->status);
        $this->assertEquals($this->organizerA->id, $event->organizer_id);

        $response->assertRedirect(route('organizer.events.show', $event));

        $this->assertCount(3, $event->accessPoints);
        $this->assertTrue($event->accessPoints()->where('code', 'MAIN')->exists());
        $this->assertTrue($event->accessPoints()->where('code', 'GATE-A')->exists());
        $this->assertTrue($event->accessPoints()->where('code', 'GATE-B')->exists());
    }

    public function test_event_island_is_limited_to_the_event_overview_page(): void
    {
        $startsAt = Carbon::parse('2027-02-15 08:30:00');
        $endsAt = Carbon::parse('2027-02-15 16:45:00');
        $registrationOpensAt = Carbon::parse('2027-01-10 09:15:00');
        $registrationClosesAt = Carbon::parse('2027-02-10 17:30:00');

        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Island Navigation Test',
            'slug' => 'island-navigation-test',
            'capacity' => 100,
            'description' => 'A readable overview with the full event details.',
            're_entry_allowed' => true,
            'identity_verification_level' => 'id_check',
            'registration_opens_at' => $registrationOpensAt,
            'registration_closes_at' => $registrationClosesAt,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $overview = $this->actingAs($this->organizerA)
            ->get(route('organizer.events.show', $event));

        $localStartsAt = EventSchedule::local($startsAt);
        $localEndsAt = EventSchedule::local($endsAt);
        $localRegistrationOpensAt = EventSchedule::local($registrationOpensAt);
        $localRegistrationClosesAt = EventSchedule::local($registrationClosesAt);

        $overview->assertOk()
            ->assertSee('data-event-island', false)
            ->assertSee('data-island-target="event-gates"', false)
            ->assertSee('Capacity Overview')
            ->assertSee('Event schedule and details')
            ->assertSee('Registration period')
            ->assertSee('Description')
            ->assertSee('A readable overview with the full event details.')
            ->assertSee($localStartsAt->format('M d, Y h:i A'))
            ->assertSee($localEndsAt->format('M d, Y h:i A'))
            ->assertSee($localRegistrationOpensAt->format('M d, Y h:i A'))
            ->assertSee($localRegistrationClosesAt->format('M d, Y h:i A'))
            ->assertSee('Re-entry')
            ->assertSee('Identity')
            ->assertSee('No transfer requests yet.')
            ->assertSee('Disabled');

        $page = $overview->getContent();
        $capacityPosition = strpos($page, 'id="event-capacity-title"');
        $schedulePosition = strpos($page, 'id="event-schedule-heading"');
        $this->assertNotFalse($capacityPosition);
        $this->assertNotFalse($schedulePosition);
        $this->assertLessThan($schedulePosition, $capacityPosition);

        $this->actingAs($this->organizerA)
            ->get(route('organizer.events.edit', $event))
            ->assertOk()
            ->assertDontSee('data-event-island', false);
    }

    public function test_event_edit_uses_four_grouped_sections_while_creation_uses_four_steps(): void
    {
        $create = $this->actingAs($this->organizerA)
            ->get(route('organizer.events.create'))
            ->assertOk()
            ->assertSee('data-event-step="1"', false)
            ->assertSee('data-event-step="2"', false)
            ->assertSee('data-event-step="3"', false)
            ->assertSee('data-event-step="4"', false);

        $this->assertSame(4, substr_count($create->getContent(), 'data-event-step='));

        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Grouped Form Test',
            'slug' => 'grouped-form-test',
            'capacity' => 100,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(4),
            'status' => Event::STATUS_DRAFT,
        ]);

        $edit = $this->actingAs($this->organizerA)
            ->get(route('organizer.events.edit', $event))
            ->assertOk()
            ->assertSee('Basic Information')
            ->assertSee('Event Schedule')
            ->assertSee('Registration Settings')
            ->assertSee('Admission Policies')
            ->assertSee('Save Changes');

        $this->assertSame(4, substr_count($edit->getContent(), 'event-form-section__header'));
    }

    public function test_event_create_wizard_explains_the_four_steps_and_current_student_email_policy(): void
    {
        $this->actingAs($this->organizerA)
            ->get(route('organizer.events.create'))
            ->assertOk()
            ->assertSee('Step 1 of 4')
            ->assertSee('data-event-step="1"', false)
            ->assertSee('data-event-step="2"', false)
            ->assertSee('data-event-step="3"', false)
            ->assertSee('data-event-step="4"', false)
            ->assertSee('Public — guests can check out without a Firebase account')
            ->assertSee('Students — a verified Firebase student account is required')
            ->assertSee('current policy allows personal email for verified Firebase student accounts')
            ->assertSee('All four times use Philippine Time (PHT).')
            ->assertSee('For a demo, use future placeholder dates and label the event “Demo” or “Simulation.”')
            ->assertSee('Allow re-entry')
            ->assertSee('Allow ticket transfers')
            ->assertSee('Approved ID check')
            ->assertSee('This creates a draft only.')
            ->assertSee('Main Entrance, Gate A, and Gate B');

        DB::table('student_access_settings')->where('id', 1)->update([
            'require_institutional_email' => true,
            'policy_enabled_at' => now(),
        ]);

        $this->get(route('organizer.events.create'))
            ->assertOk()
            ->assertSee('student.mseuf.edu.ph')
            ->assertSee('Email verification confirms mailbox access, not current enrollment.');
    }

    public function test_create_wizard_returns_to_schedule_step_and_preserves_values_after_validation_error(): void
    {
        $opensAt = Carbon::now('Asia/Manila')->addDays(5)->setTime(8, 0);
        $startsAt = $opensAt->copy()->addHours(3);
        $this->actingAs($this->organizerA)
            ->from(route('organizer.events.create'))
            ->post(route('organizer.events.store'), [
                'venue_id' => $this->venue->id,
                'title' => 'Schedule Error Demo',
                'capacity' => 80,
                'registration_opens_at' => $opensAt->format('Y-m-d\\TH:i'),
                'registration_closes_at' => $startsAt->format('Y-m-d\\TH:i'),
                'starts_at' => $startsAt->format('Y-m-d\\TH:i'),
                'ends_at' => $startsAt->copy()->addHours(4)->format('Y-m-d\\TH:i'),
            ])
            ->assertRedirect(route('organizer.events.create'))
            ->assertSessionHasErrors('registration_closes_at')
            ->assertSessionHasInput('title', 'Schedule Error Demo')
            ->assertSessionHasInput('registration_closes_at', $startsAt->format('Y-m-d\\TH:i'));

        $this->withViewErrors([
            'registration_closes_at' => ['Registration must close before the event starts.'],
        ])->view('organizer.events.create', [
            'venues' => collect([$this->venue]),
            'requiresInstitutionalEmail' => true,
            'institutionalDomain' => 'student.mseuf.edu.ph',
        ])
            ->assertSee('data-start-step="2"', false)
            ->assertSee('Some details need attention.')
            ->assertSee('Registration must close before the event starts.');
        $this->assertDatabaseMissing('events', ['title' => 'Schedule Error Demo']);
    }

    public function test_student_demo_setup_has_free_student_tickets_multiple_gates_and_optional_reentry(): void
    {
        $startsAt = Carbon::now('Asia/Manila')->addDays(10)->setTime(8, 0);
        $this->actingAs($this->organizerA)->post(route('organizer.events.store'), [
            'venue_id' => $this->venue->id,
            'title' => 'MSEUF Intramurals 2026 — Demo',
            'capacity' => 120,
            'registration_eligibility' => Event::ELIGIBILITY_STUDENT,
            'registration_opens_at' => $startsAt->copy()->subDay()->format('Y-m-d\\TH:i'),
            'registration_closes_at' => $startsAt->copy()->subHour()->format('Y-m-d\\TH:i'),
            'starts_at' => $startsAt->format('Y-m-d\\TH:i'),
            'ends_at' => $startsAt->copy()->addHours(6)->format('Y-m-d\\TH:i'),
        ])->assertRedirect();

        $event = Event::where('title', 'MSEUF Intramurals 2026 — Demo')->firstOrFail();
        $category = $event->ticketCategories()->firstOrFail();
        $this->assertSame(Event::STATUS_DRAFT, $event->status);
        $this->assertSame(0, $category->price_minor);
        $this->assertSame('student', $category->eligibility);
        $this->assertFalse($event->re_entry_allowed);
        $this->assertSame(['GATE-A', 'GATE-B', 'MAIN'], $event->accessPoints()->orderBy('code')->pluck('code')->all());
    }

    public function test_ccms_demo_can_enable_student_ticket_reentry(): void
    {
        $startsAt = Carbon::now('Asia/Manila')->addDays(12)->setTime(18, 0);
        $this->actingAs($this->organizerA)->post(route('organizer.events.store'), [
            'venue_id' => $this->venue->id,
            'title' => 'CCMS Year End Party — Demo',
            'capacity' => 100,
            'registration_eligibility' => Event::ELIGIBILITY_STUDENT,
            'registration_opens_at' => $startsAt->copy()->subDays(5)->format('Y-m-d\\TH:i'),
            'registration_closes_at' => $startsAt->copy()->subHour()->format('Y-m-d\\TH:i'),
            'starts_at' => $startsAt->format('Y-m-d\\TH:i'),
            'ends_at' => $startsAt->copy()->addHours(5)->format('Y-m-d\\TH:i'),
            're_entry_allowed' => true,
        ])->assertRedirect();

        $event = Event::where('title', 'CCMS Year End Party — Demo')->firstOrFail();
        $this->assertTrue($event->re_entry_allowed);
        $this->assertSame(0, $event->ticketCategories()->firstOrFail()->price_minor);
    }

    public function test_public_demo_event_starts_with_free_category_and_can_be_configured_after_creation(): void
    {
        config(['ingress.mode' => 'cloud']);
        $startsAt = Carbon::now('Asia/Manila')->addDays(10)->setTime(8, 0);
        $this->actingAs($this->organizerA)->post(route('organizer.events.store'), [
            'venue_id' => $this->venue->id,
            'title' => 'Miss Enverga University 2026 — Demo',
            'capacity' => 80,
            'registration_eligibility' => Event::ELIGIBILITY_PUBLIC,
            'registration_opens_at' => $startsAt->copy()->subDays(2)->format('Y-m-d\\TH:i'),
            'registration_closes_at' => $startsAt->copy()->subHour()->format('Y-m-d\\TH:i'),
            'starts_at' => $startsAt->format('Y-m-d\\TH:i'),
            'ends_at' => $startsAt->copy()->addHours(4)->format('Y-m-d\\TH:i'),
        ])->assertRedirect();

        $event = Event::where('title', 'Miss Enverga University 2026 — Demo')->firstOrFail();
        $category = $event->ticketCategories()->firstOrFail();
        $this->assertSame(0, $category->price_minor);
        $this->assertSame('public', $category->eligibility);
        $this->get(route('organizer.events.show', $event))
            ->assertOk()
            ->assertSee('Price (PHP)')
            ->assertSee('Ticket categories and access points are configured here after event creation.')
            ->assertSee('Create → Configure → Publish → Prepare for Admission')
            ->assertSee('Preparing a snapshot locks event, ticket, gate, and staff details.')
            ->assertSee('Transferring authority to the Node is irreversible');
    }

    public function test_organizer_schedule_round_trips_as_philippine_time_while_storing_utc(): void
    {
        config(['ingress.mode' => 'cloud']);

        $opensAt = Carbon::now('Asia/Manila')->addDays(10)->setTime(8, 0);
        $closesAt = $opensAt->copy()->addHours(2);
        $startsAt = $opensAt->copy()->addHours(3);
        $endsAt = $opensAt->copy()->addHours(4);
        $schedule = [
            'registration_opens_at' => $opensAt,
            'registration_closes_at' => $closesAt,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];

        $this->actingAs($this->organizerA)
            ->post('/organizer/events', [
                'venue_id' => $this->venue->id,
                'title' => 'Philippine Time Event',
                'capacity' => 45,
                ...array_map(fn (Carbon $time): string => $time->format('Y-m-d\\TH:i'), $schedule),
            ]);

        $event = Event::where('title', 'Philippine Time Event')->firstOrFail();

        foreach ($schedule as $field => $time) {
            $this->assertSame($time->copy()->utc()->toISOString(), $event->$field->toISOString());
        }

        $this->get(route('organizer.events.edit', $event))
            ->assertOk()
            ->assertSee('name="starts_at" value="'.$startsAt->format('Y-m-d\\TH:i').'"', false)
            ->assertSee('Philippine Time (PHT)');

        $this->get(route('organizer.events.show', $event))
            ->assertOk()
            ->assertSee($startsAt->format('M d, Y h:i A'))
            ->assertSee($endsAt->format('M d, Y h:i A'))
            ->assertSee('Philippine Time, PHT');

        $this->post(route('organizer.events.publish', $event))->assertRedirect();
        $this->get(route('public.events.show', $event->slug))
            ->assertOk()
            ->assertSee('Date and time (PHT)')
            ->assertSee($startsAt->format('F j, Y'))
            ->assertSee($startsAt->format('g:i A'));

        $this->post(route('organizer.events.registration.close', $event))->assertRedirect();
        $this->post(route('organizer.events.snapshot.prepare', $event))->assertRedirect();
        $snapshot = DB::table('event_snapshots')->where('event_id', $event->id)->first();
        $this->assertNotNull($snapshot);
        $payload = json_decode(Crypt::decryptString($snapshot->payload_ciphertext), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($opensAt->copy()->utc()->toISOString(), $payload['event']['registration_opens_at']);
        $this->assertSame($startsAt->copy()->utc()->toISOString(), $payload['event']['starts_at']);
    }

    public function test_create_form_defaults_registration_to_close_before_the_event_starts(): void
    {
        $this->freezeTime();

        $this->actingAs($this->organizerA)
            ->get('/organizer/events/create')
            ->assertOk()
            ->assertSee('name="registration_closes_at" value="'.Carbon::now('Asia/Manila')->addDay()->format('Y-m-d\\TH:i').'"', false)
            ->assertSee('name="starts_at" value="'.Carbon::now('Asia/Manila')->addDays(2)->setHour(8)->setMinute(0)->format('Y-m-d\\TH:i').'"', false);
    }

    public function test_organizer_cannot_create_an_event_that_accepts_registrations_after_it_starts(): void
    {
        $this->freezeTime();
        $now = Carbon::now('Asia/Manila')->toImmutable();

        $this->actingAs($this->organizerA)
            ->post('/organizer/events', [
                'venue_id' => $this->venue->id,
                'title' => 'Late Registration Event',
                'capacity' => 45,
                'registration_opens_at' => $now->format('Y-m-d\\TH:i'),
                'registration_closes_at' => $now->addDays(3)->format('Y-m-d\\TH:i'),
                'starts_at' => $now->addDays(2)->format('Y-m-d\\TH:i'),
                'ends_at' => $now->addDays(4)->format('Y-m-d\\TH:i'),
            ])
            ->assertSessionHasErrors('registration_closes_at');

        $this->assertDatabaseMissing('events', ['title' => 'Late Registration Event']);
    }

    public function test_organizer_cannot_edit_registration_cutoff_to_after_event_start(): void
    {
        $this->freezeTime();
        $now = Carbon::now()->toImmutable();
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Cutoff Edit Event',
            'slug' => 'cutoff-edit-event',
            'capacity' => 45,
            'registration_opens_at' => $now,
            'registration_closes_at' => $now->addDay(),
            'starts_at' => $now->addDays(2),
            'ends_at' => $now->addDays(3),
            'status' => Event::STATUS_DRAFT,
        ]);

        $this->actingAs($this->organizerA)
            ->put("/organizer/events/{$event->id}", [
                'venue_id' => $this->venue->id,
                'title' => $event->title,
                'capacity' => $event->capacity,
                'registration_opens_at' => $now->setTimezone('Asia/Manila')->format('Y-m-d\\TH:i'),
                'registration_closes_at' => $now->addDays(3)->setTimezone('Asia/Manila')->format('Y-m-d\\TH:i'),
                'starts_at' => $now->addDays(2)->setTimezone('Asia/Manila')->format('Y-m-d\\TH:i'),
                'ends_at' => $now->addDays(4)->setTimezone('Asia/Manila')->format('Y-m-d\\TH:i'),
            ])
            ->assertSessionHasErrors('registration_closes_at');

        $this->assertSame(
            $now->addDay()->toDateTimeString(),
            $event->fresh()->registration_closes_at->toDateTimeString(),
        );
    }

    public function test_organizer_can_publish_draft_event(): void
    {
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Orientation 2026',
            'slug' => 'orientation-2026',
            'capacity' => 100,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/publish");

        $response->assertRedirect(route('organizer.events.show', $event));
        $this->assertEquals(Event::STATUS_PUBLISHED, $event->fresh()->status);
    }

    public function test_organizer_can_cancel_published_event(): void
    {
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Cancelled Fest',
            'slug' => 'cancelled-fest',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/cancel");

        $response->assertRedirect(route('organizer.events.show', $event));
        $this->assertEquals(Event::STATUS_CANCELLED, $event->fresh()->status);
    }

    public function test_organizer_cannot_modify_another_organizers_event(): void
    {
        $eventB = Event::create([
            'organizer_id' => $this->organizerB->id,
            'venue_id' => $this->venue->id,
            'title' => 'Organizer B Private Event',
            'slug' => 'organizer-b-private',
            'capacity' => 20,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($this->organizerA)
            ->put("/organizer/events/{$eventB->id}", [
                'venue_id' => $this->venue->id,
                'title' => 'Hacked Title',
                'capacity' => 999,
                'registration_opens_at' => Carbon::now()->toDateTimeString(),
                'registration_closes_at' => Carbon::now()->addDays(2)->toDateTimeString(),
                'starts_at' => Carbon::now()->addDays(3)->toDateTimeString(),
                'ends_at' => Carbon::now()->addDays(3)->addHours(2)->toDateTimeString(),
            ]);

        $response->assertStatus(403);
        $this->assertEquals('Organizer B Private Event', $eventB->fresh()->title);

        $cancelResponse = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$eventB->id}/cancel");

        $cancelResponse->assertStatus(403);
    }

    public function test_student_cannot_access_organizer_event_routes(): void
    {
        $response = $this->actingAs($this->student)
            ->get('/organizer/events/create');

        $response->assertRedirect(route('login'));
    }

    public function test_organizer_can_add_custom_access_point_and_assign_staff(): void
    {
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Staff Test Event',
            'slug' => 'staff-test-event',
            'capacity' => 100,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(6),
            'ends_at' => Carbon::now()->addDays(6)->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $staffUser = User::factory()->create([
            'email' => 'scanner.staff@mseuf.edu.ph',
            'role' => User::ROLE_STAFF,
        ]);

        $gateResponse = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/access-points", [
                'name' => 'South Turnstile',
                'code' => 'GATE-S',
            ]);

        $gateResponse->assertSessionHas('success');
        $gate = $event->accessPoints()->where('code', 'GATE-S')->first();
        $this->assertNotNull($gate);

        $assignResponse = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/staff", [
                'user_id' => $staffUser->id,
                'access_point_id' => $gate->id,
            ]);

        $assignResponse->assertSessionHas('success');
        $this->assertTrue(
            $event->staffAssignments()
                ->where('user_id', $staffUser->id)
                ->where('access_point_id', $gate->id)
                ->exists()
        );
    }

    public function test_organizer_can_assign_a_student_as_scanner_staff_without_changing_their_account_role(): void
    {
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Validation Test Event',
            'slug' => 'val-test-event',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event->id}/staff", [
                'user_id' => $this->student->id, // Student role, not staff!
            ]);

        $response->assertSessionHas('success');
        $this->assertTrue($event->staffAssignments()->where('user_id', $this->student->id)->exists());
        $this->assertSame(User::ROLE_STUDENT, $this->student->fresh()->role);
    }

    public function test_organizer_cannot_assign_gate_from_another_event(): void
    {
        $event1 = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Event 1',
            'slug' => 'event-1',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);

        $event2 = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Event 2',
            'slug' => 'event-2',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);

        $event2Gate = AccessPoint::create([
            'event_id' => $event2->id,
            'name' => 'Event 2 Gate',
            'code' => 'E2-GATE',
        ]);

        $staffUser = User::factory()->create(['role' => User::ROLE_STAFF]);

        $response = $this->actingAs($this->organizerA)
            ->post("/organizer/events/{$event1->id}/staff", [
                'user_id' => $staffUser->id,
                'access_point_id' => $event2Gate->id, // Belongs to event 2, not event 1!
            ]);

        $response->assertSessionHasErrors(['access_point_id']);
    }

    public function test_capacity_cannot_be_lowered_below_confirmed_registrations(): void
    {
        $now = Carbon::now();
        $event = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $this->venue->id,
            'title' => 'Capacity Floor Event',
            'slug' => 'capacity-floor-event',
            'capacity' => 500,
            'registration_opens_at' => $now->copy()->subDay(),
            'registration_closes_at' => $now->copy()->addDays(5),
            'starts_at' => $now->copy()->addDays(6),
            'ends_at' => $now->copy()->addDays(6)->addHours(3),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        User::factory()->count(420)->create(['role' => User::ROLE_STUDENT])
            ->each(function (User $student) use ($event) {
                Registration::create([
                    'event_id' => $event->id,
                    'user_id' => $student->id,
                    'status' => Registration::STATUS_CONFIRMED,
                ]);
            });

        $payload = fn (int $capacity) => [
            'venue_id' => $this->venue->id,
            'title' => $event->title,
            'description' => null,
            'capacity' => $capacity,
            'registration_opens_at' => $now->copy()->subDay()->setTimezone('Asia/Manila')->format('Y-m-d\\TH:i'),
            'registration_closes_at' => $now->copy()->addDays(5)->setTimezone('Asia/Manila')->format('Y-m-d\\TH:i'),
            'starts_at' => $now->copy()->addDays(6)->setTimezone('Asia/Manila')->format('Y-m-d\\TH:i'),
            'ends_at' => $now->copy()->addDays(6)->addHours(3)->setTimezone('Asia/Manila')->format('Y-m-d\\TH:i'),
        ];

        $this->actingAs($this->organizerA)
            ->put("/organizer/events/{$event->id}", $payload(300))
            ->assertSessionHasErrors(['capacity']);

        $this->assertEquals(500, $event->fresh()->capacity, 'rejected update must not change capacity');

        $this->actingAs($this->organizerA)
            ->put("/organizer/events/{$event->id}", $payload(420))
            ->assertSessionHasNoErrors();

        $this->assertEquals(420, $event->fresh()->capacity);
    }
}
