<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventManagerAssignment;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicEventRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Venue $venue;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->flush();
        config(['ingress.mode' => 'cloud']);

        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->venue = Venue::create([
            'name' => 'MSEUF Gymnasium',
            'code' => 'GYM',
            'location' => 'Main Campus',
            'default_capacity' => 100,
        ]);

        $this->event = Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'Public Symposium',
            'slug' => 'public-symposium',
            'description' => 'A public university symposium.',
            'capacity' => 3,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
        ]);
    }

    public function test_public_catalog_and_event_details_are_available_without_an_account(): void
    {
        $category = $this->category($this->event, 'General Admission', isDefault: true);

        $this->get('/')->assertRedirect(route('public.events.index'));
        $this->get(route('public.events.index'))
            ->assertOk()
            ->assertSee('Public Symposium')
            ->assertSee('General Admission');

        $this->get(route('public.events.show', $this->event->slug))
            ->assertOk()
            ->assertSee('public university symposium.')
            ->assertSee('General Admission')
            ->assertSee(route('public.events.register', $this->event->slug), false);

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
        $this->assertTrue($category->is_active);
    }

    public function test_student_only_event_registration_does_not_render_guest_checkout_fields(): void
    {
        $this->event->update(['registration_eligibility' => Event::ELIGIBILITY_STUDENT]);
        $this->category($this->event, 'Student Admission', isDefault: true);

        $this->get(route('public.events.register', $this->event->slug))
            ->assertOk()
            ->assertSee('A Firebase-verified student account is required.')
            ->assertSee('Personal email addresses are accepted under the current student access policy.')
            ->assertSee('Email verification confirms mailbox access, not current enrollment.')
            ->assertDontSee('id="guest_name"', false)
            ->assertDontSee('id="guest_email"', false);
    }

    public function test_verified_personal_student_can_register_for_a_student_only_category_when_policy_is_off(): void
    {
        $category = $this->category($this->event, 'Student Admission', eligibility: 'student', isDefault: true);
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'category.personal@gmail.com',
            'firebase_uid' => 'firebase-personal-category-student',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($student)
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'ticket_category_id' => $category->id,
            'user_id' => $student->id,
            'attendee_type' => 'student',
        ]);
        $this->assertSame(1, Ticket::count());
    }

    public function test_verified_personal_student_can_register_for_student_only_event_when_policy_is_off_and_is_blocked_when_enabled(): void
    {
        $this->event->update(['registration_eligibility' => Event::ELIGIBILITY_STUDENT]);
        $category = $this->category($this->event, 'Student Admission', isDefault: true);
        $personalStudent = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'personal.student@gmail.com',
            'firebase_uid' => 'firebase-personal-student-off',
            'email_verified_at' => now(),
        ]);

        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Ignored Guest',
            'guest_email' => 'guest@example.test',
        ])->assertRedirect(route('student.auth'));

        $response = $this->actingAs($personalStudent)
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'user_id' => $personalStudent->id,
            'attendee_type' => 'student',
        ]);
        $response->assertRedirect(route('public.tickets.show', Registration::query()->sole()->id));

        $this->assertSame(1, Ticket::count());

        DB::table('student_access_settings')->where('id', 1)->update([
            'require_institutional_email' => true,
            'policy_enabled_at' => now(),
        ]);
        $newPersonalStudent = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'another.personal@gmail.com',
            'firebase_uid' => 'firebase-personal-student-on',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($newPersonalStudent)
            ->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
            ])
            ->assertSessionHasErrors('ticket_category_id');

        $this->assertSame(1, Registration::count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_student_only_category_hides_guest_details_without_affecting_public_guest_checkout(): void
    {
        $publicCategory = $this->category($this->event, 'General Admission', isDefault: true);
        $studentCategory = $this->category(
            $this->event,
            'Verified Student Admission',
            eligibility: TicketCategory::ELIGIBILITY_STUDENT,
        );

        $studentResponse = $this->get(route('public.events.register', $this->event->slug).'?category='.$studentCategory->id)
            ->assertOk()
            ->assertSeeText('Guest contact details are not needed. Email verification confirms mailbox access, not current enrollment.')
            ->assertSee('data-student-only="true"', false);
        $this->assertMatchesRegularExpression('/<div id="guest-checkout-fields"\s+hidden(?:\s|>)/', $studentResponse->getContent());

        $this->get(route('public.events.register', $this->event->slug).'?category='.$publicCategory->id)
            ->assertOk()
            ->assertSee('id="guest_name"', false)
            ->assertSee('id="guest_email"', false)
            ->assertSee('data-student-only="false"', false);
    }

    public function test_authenticated_students_can_reach_their_tickets_from_public_navigation(): void
    {
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'nav@student.mseuf.edu.ph',
            'firebase_uid' => 'firebase-student-nav',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('public.events.index'))
            ->assertOk()
            ->assertSee('My tickets')
            ->assertSee(route('student.dashboard'), false);

        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->actingAs($organizer)
            ->get(route('public.events.index'))
            ->assertOk()
            ->assertDontSee('My tickets');
    }

    public function test_guest_can_register_for_a_free_public_category_and_view_a_session_bound_pass(): void
    {
        $category = $this->category($this->event, 'General Admission', capacity: 2, isDefault: true);
        $email = 'attendee@example.test';

        $response = $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
                'guest_name' => 'Taylor Guest',
                'guest_email' => $email,
            ]);

        $registration = Registration::query()->sole();
        $ticket = Ticket::query()->sole();

        $response->assertRedirect(route('public.tickets.show', $registration->id));
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertNull($registration->user_id);
        $this->assertSame('guest', $registration->attendee_type);
        $this->assertSame(mb_strtolower($email), $registration->guest_email);
        $this->assertSame($category->id, $registration->ticket_category_id);
        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->status);

        $pass = $this->get(route('public.tickets.show', $registration->id));
        $pass->assertOk()
            ->assertSee('Public Symposium')
            ->assertSee('Taylor Guest')
            ->assertSee('<svg', false)
            ->assertDontSee($email)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $ticket->update(['credential_version' => 2]);
        $this->get(route('public.tickets.show', $registration->id))->assertNotFound();

        $this->flushSession();
        $this->get(route('public.tickets.show', $registration->id))->assertNotFound();
    }

    public function test_guest_checkout_rejects_duplicate_email_and_respects_category_capacity(): void
    {
        $category = $this->category($this->event, 'General Admission', capacity: 1, isDefault: true);
        $payload = [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Taylor Guest',
            'guest_email' => 'attendee@example.test',
        ];

        $this->post(route('public.events.register.store', $this->event->slug), $payload)
            ->assertRedirect(route('public.tickets.show', Registration::query()->sole()->id));

        $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [...$payload, 'guest_name' => 'Second Holder'])
            ->assertSessionHasErrors('registration');

        $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                ...$payload,
                'guest_name' => 'Another Guest',
                'guest_email' => 'another@example.test',
            ])
            ->assertSessionHasErrors('capacity');

        $this->assertSame(1, Registration::count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_guest_contact_email_does_not_block_verified_student_registration(): void
    {
        $guestCategory = $this->category($this->event, 'General Admission', isDefault: true);
        $studentCategory = $this->category(
            $this->event,
            'Verified Student Admission',
            eligibility: TicketCategory::ELIGIBILITY_STUDENT,
        );
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'shared@student.mseuf.edu.ph',
            'firebase_uid' => 'firebase-shared-contact',
            'email_verified_at' => now(),
        ]);

        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $guestCategory->id,
            'guest_name' => 'Accountless Guest',
            'guest_email' => $student->email,
        ])->assertRedirect();

        $this->actingAs($student)
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $studentCategory->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'user_id' => $student->id,
            'attendee_type' => 'student',
            'guest_email' => null,
        ]);
        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'user_id' => null,
            'attendee_type' => 'guest',
            'guest_email' => $student->email,
        ]);
        $this->assertSame(2, Registration::where('event_id', $this->event->id)->count());
        $this->assertSame(2, Ticket::count());
    }

    public function test_student_only_category_requires_a_verified_institutional_firebase_identity(): void
    {
        DB::table('student_access_settings')->where('id', 1)->update([
            'require_institutional_email' => true,
            'policy_enabled_at' => now(),
        ]);
        $category = $this->category($this->event, 'Student Admission', eligibility: 'student', isDefault: true);

        $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
                'guest_name' => 'Unverified Guest',
                'guest_email' => 'student@example.test',
            ])
            ->assertRedirect(route('student.auth'))
            ->assertSessionHas('public_registration_intent');

        $unverified = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'unverified.personal@gmail.com',
            'firebase_uid' => 'firebase-unverified-event-eligibility',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($unverified)
            ->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
            ])
            ->assertSessionHasErrors('ticket_category_id');

        $wrongDomain = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'wrong-domain@example.test',
            'firebase_uid' => 'firebase-wrong-domain',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($wrongDomain)
            ->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
            ])
            ->assertSessionHasErrors('ticket_category_id');

        $verified = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'verified@student.mseuf.edu.ph',
            'firebase_uid' => 'firebase-verified-student',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($verified)
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
            ])
            ->assertRedirect(route('public.tickets.show', Registration::query()->sole()->id));

        $registration = Registration::query()->sole();
        $this->assertSame($verified->id, $registration->user_id);
        $this->assertSame('student', $registration->attendee_type);
        $this->assertNull($registration->guest_email);
        $this->assertSame(1, Ticket::count());
    }

    public function test_firebase_sign_in_returns_to_the_pending_student_category_without_using_an_external_redirect(): void
    {
        $category = $this->category($this->event, 'Student Admission', eligibility: 'student', isDefault: true);
        DB::table('student_access_settings')->where('id', 1)->update(['require_institutional_email' => false]);
        config([
            'services.firebase.api_key' => 'public-test-api-key',
            'services.firebase.project_id' => 'ingress-test',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => 'firebase-student-pending',
                    'email' => 'pending.personal@gmail.com',
                    'emailVerified' => true,
                    'displayName' => 'Pending Student',
                    'disabled' => false,
                    'providerUserInfo' => [['providerId' => 'password']],
                ]],
            ]),
        ]);

        $this->withSession([
            'public_registration_intent' => [
                'event_id' => $this->event->id,
                'category_id' => $category->id,
                'redirect' => 'https://attacker.example/',
            ],
        ])->postJson('/student/session', ['id_token' => 'firebase-id-token'])
            ->assertOk()
            ->assertJsonPath('redirect', route('public.events.register', [
                'event' => $this->event->slug,
                'category' => $category->id,
            ], absolute: false));

        $student = User::query()->where('firebase_uid', 'firebase-student-pending')->firstOrFail();
        $this->assertAuthenticatedAs($student, 'web');

        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $category->id,
        ])->assertRedirect(route('public.tickets.show', Registration::query()->sole()->id));

        $this->assertSame($student->id, Registration::query()->sole()->user_id);
    }

    public function test_paid_category_is_not_issued_without_the_simulated_checkout_phase(): void
    {
        $category = $this->category($this->event, 'Paid Admission', priceMinor: 12500, isDefault: true);
        $this->event->ticketCategories()->where('name', 'General Admission')->update(['is_active' => false]);

        $this->get(route('public.events.register', $this->event->slug))
            ->assertOk()
            ->assertSee('No ticket categories are currently available for checkout.')
            ->assertDontSee('name="guest_email"', false);

        $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
                'guest_name' => 'Paid Guest',
                'guest_email' => 'paid@example.test',
            ])
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_draft_cancelled_closed_and_full_events_cannot_accept_public_registration(): void
    {
        $category = $this->category($this->event, 'General Admission', isDefault: true);
        $this->event->update(['status' => Event::STATUS_DRAFT]);
        $this->assertRegistrationRejected($category, 'registration');

        $this->event->update(['status' => Event::STATUS_CANCELLED]);
        $this->assertRegistrationRejected($category, 'registration');

        $this->event->update([
            'status' => Event::STATUS_PUBLISHED,
            'registration_closed_at' => now(),
        ]);
        $this->assertRegistrationRejected($category, 'registration');

        $this->event->forceFill(['registration_closed_at' => null, 'snapshot_prepared_at' => now()])->save();
        $this->assertRegistrationRejected($category, 'registration');

        $this->event->forceFill(['snapshot_prepared_at' => null])->save();
        $this->event->update(['capacity' => 1]);
        Registration::create([
            'event_id' => $this->event->id,
            'user_id' => null,
            'attendee_type' => 'guest',
            'guest_name' => 'Existing Guest',
            'guest_email' => 'existing@example.test',
            'ticket_category_id' => $category->id,
            'status' => Registration::STATUS_CONFIRMED,
        ]);
        $this->assertRegistrationRejected($category, 'capacity');

        $this->assertSame(0, Ticket::count());
    }

    public function test_legacy_student_api_registration_uses_the_same_category_capacity_and_event_lock(): void
    {
        $category = $this->category($this->event, 'General Admission', capacity: 1, isDefault: true);
        $this->event->update(['capacity' => 1]);
        $this->guestCheckout($category, 'first@example.test');

        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'legacy@student.mseuf.edu.ph',
            'firebase_uid' => 'firebase-legacy-api-registration',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($student)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['capacity']);

        $this->assertSame(1, Registration::where('event_id', $this->event->id)->count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_student_api_registration_requires_a_verified_firebase_identity(): void
    {
        $this->category($this->event, 'General Admission', isDefault: true);
        $unverified = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'firebase_uid' => null,
            'email_verified_at' => null,
        ]);

        $this->actingAs($unverified)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only verified student accounts can register.');

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_student_api_registration_allows_verified_personal_email_for_student_only_categories_when_policy_is_off(): void
    {
        $this->category($this->event, 'Student Admission', eligibility: 'student', isDefault: true);
        DB::table('student_access_settings')->where('id', 1)->update(['require_institutional_email' => false]);
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'api.personal@gmail.com',
            'firebase_uid' => 'firebase-api-personal-student',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($student)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertCreated();

        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'user_id' => $student->id,
            'attendee_type' => 'student',
        ]);
        $this->assertSame(1, Ticket::count());
    }

    public function test_student_api_registration_respects_the_active_institutional_email_policy(): void
    {
        $this->category($this->event, 'General Admission', isDefault: true);
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'verified.personal@gmail.com',
            'firebase_uid' => 'firebase-personal-registration',
            'email_verified_at' => now(),
            'created_at' => now()->subDay(),
        ]);

        $this->actingAs($student)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertCreated();

        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'user_id' => $student->id,
        ]);

        DB::table('student_access_settings')->where('id', 1)->update([
            'require_institutional_email' => true,
            'policy_enabled_at' => now(),
        ]);

        $anotherEvent = Event::create([
            'organizer_id' => $this->event->organizer_id,
            'venue_id' => $this->venue->id,
            'title' => 'Second Public Symposium',
            'slug' => 'second-public-symposium',
            'capacity' => 3,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(2),
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
        ]);
        $this->category($anotherEvent, 'General Admission', isDefault: true);

        $this->actingAs($student)
            ->postJson("/api/v1/events/{$anotherEvent->id}/registration")
            ->assertForbidden()
            ->assertJsonPath('message', 'New student registrations require an @student.mseuf.edu.ph email address.');

        $newPersonalStudent = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'new.personal@gmail.com',
            'firebase_uid' => 'firebase-new-personal-registration',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($newPersonalStudent)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertForbidden()
            ->assertJsonPath('message', 'New student registrations require an @student.mseuf.edu.ph email address.');

        $this->assertSame(1, Registration::count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_ticket_category_from_another_event_cannot_be_used_for_checkout(): void
    {
        $foreignEvent = Event::create([
            'organizer_id' => $this->event->organizer_id,
            'venue_id' => $this->venue->id,
            'title' => 'Different Event',
            'slug' => 'different-event',
            'capacity' => 10,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);
        $foreignCategory = $foreignEvent->ticketCategories()->firstOrFail();

        $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $foreignCategory->id,
                'guest_name' => 'Attempted Guest',
                'guest_email' => 'attempt@example.test',
            ])
            ->assertSessionHasErrors('ticket_category_id');

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_student_api_respects_enabled_institutional_email_policy_for_student_only_events(): void
    {
        DB::table('student_access_settings')->where('id', 1)->update([
            'require_institutional_email' => true,
            'policy_enabled_at' => now(),
        ]);
        $this->event->update(['registration_eligibility' => Event::ELIGIBILITY_STUDENT]);
        $this->event->ticketCategories()->where('is_default', true)->update(['eligibility' => 'student']);
        $personalStudent = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'verified.personal@gmail.com',
            'firebase_uid' => 'firebase-unverified-event-eligibility-api',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($personalStudent)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertForbidden()
            ->assertJsonPath('message', 'New student registrations require an @student.mseuf.edu.ph email address.');

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_public_checkout_is_rate_limited_by_event_and_requester(): void
    {
        Cache::store('array')->flush();
        $this->category($this->event, 'General Admission', isDefault: true);

        $otherEvent = Event::create([
            'organizer_id' => $this->event->organizer_id,
            'venue_id' => $this->venue->id,
            'title' => 'Other Public Event',
            'slug' => 'other-public-event',
            'capacity' => 10,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('public.events.register.store', $this->event->slug), [])->assertRedirect();
        }

        $this->post(route('public.events.register.store', $otherEvent->slug), [])->assertRedirect();
        $this->post(route('public.events.register.store', $this->event->slug), [])->assertTooManyRequests();
        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_event_manager_can_configure_categories_and_cannot_edit_after_issuance(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_EVENT_MANAGER]);
        EventManagerAssignment::create([
            'event_id' => $this->event->id,
            'user_id' => $manager->id,
            'assigned_by_user_id' => $this->event->organizer_id,
        ]);

        $this->actingAs($manager)
            ->post(route('organizer.events.ticket-categories.store', $this->event), [
                'name' => 'Student Discount',
                'price_pesos' => '12.50',
                'capacity' => 2,
                'eligibility' => 'student',
            ])
            ->assertRedirect(route('organizer.events.show', $this->event));

        $category = TicketCategory::query()->where('name', 'Student Discount')->sole();
        $this->assertSame(1250, $category->price_minor);
        $this->assertSame(2, $category->capacity);
        $this->assertSame('student', $category->eligibility);

        Registration::create([
            'event_id' => $this->event->id,
            'ticket_category_id' => $category->id,
            'user_id' => null,
            'attendee_type' => 'guest',
            'guest_name' => 'Existing Attendee',
            'guest_email' => 'existing@example.test',
            'status' => Registration::STATUS_CONFIRMED,
        ]);

        $this->put(route('organizer.events.ticket-categories.update', [$this->event, $category]), [
            'name' => 'Open Discount',
            'price_pesos' => '0.00',
            'capacity' => 2,
            'eligibility' => 'public',
        ])->assertSessionHasErrors('category');

        $unauthorizedOrganizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->actingAs($unauthorizedOrganizer)
            ->post(route('organizer.events.ticket-categories.store', $this->event), [
                'name' => 'Unapproved Category',
                'price_pesos' => '0',
                'eligibility' => 'public',
            ])
            ->assertForbidden();
    }

    private function category(
        Event $event,
        string $name,
        int $priceMinor = 0,
        ?int $capacity = null,
        string $eligibility = 'public',
        bool $isDefault = false,
    ): TicketCategory {
        if ($isDefault) {
            $event->ticketCategories()->update(['is_default' => false]);
        }

        $attributes = [
            'event_id' => $event->id,
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'PHP',
            'capacity' => $capacity,
            'eligibility' => $eligibility,
            'is_active' => true,
            'is_default' => $isDefault,
        ];
        $existing = TicketCategory::query()->where('event_id', $event->id)->where('name', $name)->first();
        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return TicketCategory::create($attributes);
    }

    private function guestCheckout(TicketCategory $category, string $email): void
    {
        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Guest Attendee',
            'guest_email' => $email,
        ]);
    }

    private function assertRegistrationRejected(TicketCategory $category, string $error): void
    {
        $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
                'guest_name' => 'Attempted Guest',
                'guest_email' => 'attempt@example.test',
            ])
            ->assertSessionHasErrors($error);
    }
}
