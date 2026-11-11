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
        config(['services.firebase.student_email_domain' => 'student.mseuf.edu.ph']);

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

    public function test_student_only_category_requires_a_verified_institutional_firebase_identity(): void
    {
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
            'email' => 'unverified@student.mseuf.edu.ph',
            'firebase_uid' => null,
            'email_verified_at' => null,
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
        config([
            'services.firebase.api_key' => 'public-test-api-key',
            'services.firebase.project_id' => 'ingress-test',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => 'firebase-student-pending',
                    'email' => 'pending@student.mseuf.edu.ph',
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
        ]);

        $this->actingAs($student)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['capacity']);

        $this->assertSame(1, Registration::where('event_id', $this->event->id)->count());
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

    public function test_student_api_cannot_bypass_event_wide_student_eligibility(): void
    {
        $this->event->update(['registration_eligibility' => Event::ELIGIBILITY_STUDENT]);
        $this->event->ticketCategories()->where('is_default', true)->update(['eligibility' => 'student']);
        $unverified = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'unverified@student.mseuf.edu.ph',
            'firebase_uid' => null,
            'email_verified_at' => null,
        ]);

        $this->actingAs($unverified)
            ->postJson("/api/v1/events/{$this->event->id}/registration")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ticket_category_id']);

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
