<?php

namespace Tests\Feature;

use App\Actions\ProcessSimulatedPayment;
use App\Models\CheckoutOrder;
use App\Models\Event;
use App\Models\EventManagerAssignment;
use App\Models\PaymentAttempt;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Models\Venue;
use App\Services\Checkout\SimulatedPaymentProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimulatedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ingress.mode' => 'cloud',
            'ingress.simulated_payments.enabled' => true,
        ]);

        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create([
            'name' => 'MSEUF Gymnasium',
            'code' => 'GYM',
            'location' => 'Main Campus',
            'default_capacity' => 10,
        ]);

        $this->event = Event::create([
            'organizer_id' => $owner->id,
            'venue_id' => $venue->id,
            'title' => 'Paid Symposium',
            'slug' => 'paid-symposium',
            'capacity' => 3,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(2),
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
        ]);
    }

    public function test_assigned_event_manager_can_explicitly_authorize_simulated_payments(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_EVENT_MANAGER]);
        EventManagerAssignment::create([
            'event_id' => $this->event->id,
            'user_id' => $manager->id,
            'assigned_by_user_id' => $this->event->organizer_id,
        ]);

        $this->actingAs($manager)
            ->post(route('organizer.events.simulated-payments.update', $this->event), ['enabled' => true])
            ->assertRedirect(route('organizer.events.show', $this->event));

        $this->assertTrue((bool) $this->event->fresh()->simulated_payments_enabled);
        $this->assertDatabaseHas('event_simulated_payment_audits', [
            'event_id' => $this->event->id,
            'actor_user_id' => $manager->id,
            'enabled' => true,
        ]);
    }

    public function test_simulated_payment_authorization_requires_event_assignment_and_deployment_switch(): void
    {
        $unassignedManager = User::factory()->create(['role' => User::ROLE_EVENT_MANAGER]);
        $this->actingAs($unassignedManager)
            ->post(route('organizer.events.simulated-payments.update', $this->event), ['enabled' => true])
            ->assertRedirect();

        $assignedManager = User::factory()->create(['role' => User::ROLE_EVENT_MANAGER]);
        EventManagerAssignment::create([
            'event_id' => $this->event->id,
            'user_id' => $assignedManager->id,
            'assigned_by_user_id' => $this->event->organizer_id,
        ]);

        config(['ingress.mode' => 'node']);
        $this->actingAs($assignedManager)
            ->post(route('organizer.events.simulated-payments.update', $this->event), ['enabled' => true])
            ->assertStatus(503);

        config(['ingress.mode' => 'cloud']);
        config(['ingress.simulated_payments.enabled' => false]);
        $this->actingAs($assignedManager)
            ->post(route('organizer.events.simulated-payments.update', $this->event), ['enabled' => true])
            ->assertNotFound();

        $this->assertFalse((bool) $this->event->fresh()->simulated_payments_enabled);
        $this->assertDatabaseCount('event_simulated_payment_audits', 0);
    }

    public function test_event_node_runtime_cannot_create_paid_checkout_orders(): void
    {
        $this->event->update(['simulated_payments_enabled' => true]);
        $category = $this->category($this->event, 'Paid Admission', priceMinor: 12500, isDefault: true);
        $idempotencyKey = $this->registrationFormKey();
        config(['ingress.mode' => 'node']);
        $this->assertFalse($this->event->canOfferSimulatedPayments());

        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Public Guest',
            'guest_email' => 'public@example.test',
            'idempotency_key' => $idempotencyKey,
        ])->assertStatus(503);

        $this->assertSame(0, CheckoutOrder::count());
        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_paid_guest_checkout_holds_capacity_without_issuing_a_ticket_and_can_be_resumed(): void
    {
        $this->event->update(['capacity' => 1, 'simulated_payments_enabled' => true]);
        $paidCategory = $this->category($this->event, 'Paid Admission', priceMinor: 12500, capacity: 1, isDefault: true);
        $freeCategory = $this->category($this->event, 'Free Admission', isDefault: false);
        $idempotencyKey = $this->registrationFormKey();
        $payload = [
            'ticket_category_id' => $paidCategory->id,
            'guest_name' => 'Taylor Guest',
            'guest_email' => 'taylor@example.test',
            'idempotency_key' => $idempotencyKey,
        ];

        $start = $this->post(route('public.events.register.store', $this->event->slug), $payload);
        $order = CheckoutOrder::query()->sole();

        $start->assertRedirect(route('public.checkout.show', $order->public_id));
        $this->post(route('public.events.register.store', $this->event->slug), $payload)
            ->assertRedirect(route('public.checkout.show', $order->public_id));

        $this->assertSame(1, CheckoutOrder::count());
        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());

        $this->get(route('public.checkout.show', $order->public_id))
            ->assertOk()
            ->assertSee('SIMULATED PAYMENT')
            ->assertSee('NO REAL MONEY IS CHARGED')
            ->assertSee('Test Wallet')
            ->assertDontSee('card_number')
            ->assertDontSee('security_code');

        $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $freeCategory->id,
                'guest_name' => 'Free Guest',
                'guest_email' => 'free@example.test',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('capacity');

        $this->flushSession();
        $this->get(route('public.checkout.show', $order->public_id))->assertNotFound();
    }

    public function test_guest_contact_reservation_does_not_block_verified_student_registration_or_payment(): void
    {
        config(['services.firebase.student_email_domain' => 'student.mseuf.edu.ph']);
        $sharedEmail = 'shared@student.mseuf.edu.ph';
        $order = $this->startPaidCheckout($sharedEmail);
        $attemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_WALLET,
            'attempt_key' => $attemptKey,
        ])->assertRedirect();

        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => $sharedEmail,
            'firebase_uid' => 'firebase-shared-paid-contact',
            'email_verified_at' => now(),
        ]);
        $freeCategory = $this->category($this->event, 'Free General Admission', isDefault: true);
        $freeCategory->update(['eligibility' => TicketCategory::ELIGIBILITY_STUDENT]);

        $this->actingAs($student)
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $freeCategory->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('registrations', [
            'event_id' => $this->event->id,
            'user_id' => $student->id,
            'attendee_type' => 'student',
        ]);

        $this->app->instance(SimulatedPaymentProcessor::class, new class extends SimulatedPaymentProcessor
        {
            public function process(PaymentAttempt $attempt): string
            {
                return PaymentAttempt::STATUS_SUCCESSFUL;
            }
        });

        $result = app(ProcessSimulatedPayment::class)->execute($order->id, $attemptKey);

        $this->assertSame(CheckoutOrder::STATUS_SUCCESSFUL, $result['status']);
        $this->assertSame($sharedEmail, $result['registration']->guest_email);
        $this->assertSame(2, Registration::where('event_id', $this->event->id)->count());
        $this->assertSame(2, Ticket::count());
    }

    public function test_active_guest_reservation_blocks_a_second_checkout_with_the_same_contact_email(): void
    {
        $email = 'repeated-guest@example.test';
        $firstOrder = $this->startPaidCheckout($email);
        $category = TicketCategory::query()->where('event_id', $this->event->id)->where('name', 'Paid Admission')->firstOrFail();

        $this->flushSession();
        $idempotencyKey = $this->registrationFormKey();
        $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
                'guest_name' => 'Second Guest',
                'guest_email' => $email,
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSessionHasErrors('registration');

        $this->assertSame(1, CheckoutOrder::count());
        $this->assertSame($firstOrder->id, CheckoutOrder::query()->sole()->id);
        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_paid_checkout_clears_stale_student_registration_intent(): void
    {
        $this->event->update(['simulated_payments_enabled' => true]);
        $category = $this->category($this->event, 'Paid Admission', priceMinor: 12500, isDefault: true);
        $this->assertSame(12500, $category->fresh()->price_minor);
        $idempotencyKey = $this->registrationFormKey();
        $this->withSession([
            'public_registration_intent' => ['event_id' => 999, 'category_id' => 999],
            'public_checkout_form_keys' => [(string) $this->event->id => [$idempotencyKey => true]],
        ]);

        $response = $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Public Guest',
            'guest_email' => 'public@example.test',
            'idempotency_key' => $idempotencyKey,
        ]);

        $response->assertRedirect()->assertSessionMissing('public_registration_intent');
    }

    public function test_client_supplied_success_status_cannot_override_a_failed_simulator_result(): void
    {
        $order = $this->startPaidCheckout();
        $attemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_CARD,
            'attempt_key' => $attemptKey,
        ])->assertRedirect(route('public.checkout.show', $order->public_id));

        $this->app->instance(SimulatedPaymentProcessor::class, new class extends SimulatedPaymentProcessor
        {
            public function process(PaymentAttempt $attempt): string
            {
                return PaymentAttempt::STATUS_FAILED;
            }
        });

        $this->post(route('public.checkout.process', $order->public_id), [
            'attempt_key' => $attemptKey,
            'payment_status' => 'successful',
        ])->assertRedirect(route('public.checkout.show', $order->public_id));

        $this->assertSame(CheckoutOrder::STATUS_FAILED, $order->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_FAILED, $order->attempts()->sole()->status);
        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_simulator_approval_issues_one_paid_ticket_and_confirmation_is_idempotent(): void
    {
        $order = $this->startPaidCheckout();
        $attemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_WALLET,
            'attempt_key' => $attemptKey,
        ])->assertRedirect(route('public.checkout.show', $order->public_id));

        $this->app->instance(SimulatedPaymentProcessor::class, new class extends SimulatedPaymentProcessor
        {
            public function process(PaymentAttempt $attempt): string
            {
                return PaymentAttempt::STATUS_SUCCESSFUL;
            }
        });

        $confirmation = [
            'attempt_key' => $attemptKey,
            'status' => 'failed',
        ];
        $response = $this->post(route('public.checkout.process', $order->public_id), $confirmation);
        $registration = Registration::query()->sole();
        $ticket = Ticket::query()->sole();

        $response->assertRedirect(route('public.tickets.show', $registration->id));
        $this->assertSame(CheckoutOrder::STATUS_SUCCESSFUL, $order->fresh()->status);
        $this->assertSame(Registration::STATUS_CONFIRMED, $registration->status);
        $this->assertSame(Ticket::STATUS_ACTIVE, $ticket->status);
        $this->assertSame($registration->id, $order->fresh()->registration_id);
        $this->assertSame(PaymentAttempt::STATUS_SUCCESSFUL, $order->attempts()->sole()->status);

        $this->post(route('public.checkout.process', $order->public_id), $confirmation)
            ->assertRedirect(route('public.tickets.show', $registration->id));

        $this->assertSame(1, Registration::count());
        $this->assertSame(1, Ticket::count());
        $this->get(route('public.tickets.show', $registration->id))->assertOk();
    }

    public function test_guest_can_resume_ticket_and_transfer_code_after_confirmation_response_is_interrupted(): void
    {
        $order = $this->startPaidCheckout();
        $attemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_WALLET,
            'attempt_key' => $attemptKey,
        ]);
        $this->app->instance(SimulatedPaymentProcessor::class, new class extends SimulatedPaymentProcessor
        {
            public function process(PaymentAttempt $attempt): string
            {
                return PaymentAttempt::STATUS_SUCCESSFUL;
            }
        });

        $result = app(ProcessSimulatedPayment::class)->execute($order->id, $attemptKey);
        $redemptionCode = $result['redemption_code'];

        $this->assertIsString($redemptionCode);
        $ciphertext = $order->fresh()->redemption_code_ciphertext;
        $this->assertIsString($ciphertext);
        $this->assertNotSame($redemptionCode, $ciphertext);
        $this->assertArrayNotHasKey('redemption_code_ciphertext', $order->fresh()->toArray());
        $this->assertSame($redemptionCode, Crypt::decryptString($ciphertext));

        $this->get(route('public.checkout.show', $order->public_id))
            ->assertOk()
            ->assertSee('View ticket');
        $this->get(route('public.tickets.show', $result['registration']->id))
            ->assertOk()
            ->assertSee($redemptionCode);

        $this->assertNull($order->fresh()->redemption_code_ciphertext);
    }

    public function test_paid_checkout_session_cannot_view_guest_ticket_after_another_session_transfers_it(): void
    {
        $this->event->update(['ticket_transfers_allowed' => true]);
        $sessionCookie = config('session.cookie');
        $sessionA = Str::random(40);
        $sessionB = Str::random(40);
        $this->assertNotSame($sessionA, $sessionB);

        $this->withCookie($sessionCookie, $sessionA);
        $order = $this->startPaidCheckout('first-holder@example.test');
        $attemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_WALLET,
            'attempt_key' => $attemptKey,
        ]);

        $this->app->instance(SimulatedPaymentProcessor::class, new class extends SimulatedPaymentProcessor
        {
            public function process(PaymentAttempt $attempt): string
            {
                return PaymentAttempt::STATUS_SUCCESSFUL;
            }
        });

        $this->post(route('public.checkout.process', $order->public_id), [
            'attempt_key' => $attemptKey,
            'status' => 'failed',
        ])->assertRedirect();

        $registration = Registration::query()->sole();
        $checkoutUrl = route('public.checkout.show', $order->public_id);
        $ticketUrl = route('public.tickets.show', $registration);
        $pdfUrl = route('public.tickets.pdf', $registration);
        $this->get($checkoutUrl)->assertOk();
        $this->assertSame($sessionA, $this->app['session.store']->getId());
        $passA = $this->get($ticketUrl)->assertOk();
        preg_match('/id="redemption-code">([a-f0-9]{64})<\/code>/', $passA->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $redemptionCodeA = $matches[1];
        $this->assertSame(hash('sha256', $redemptionCodeA), $registration->ticket->redemption_hash);
        $this->get($pdfUrl)->assertOk();

        $passB = $this->withCookie($sessionCookie, $sessionB)
            ->post(route('public.tickets.redeem.store'), [
                'redemption_code' => $redemptionCodeA,
                'guest_name' => 'Second Ticket Holder',
                'guest_email' => 'second-holder@example.test',
            ]);
        $passB->assertRedirect($ticketUrl);
        $this->assertSame('Second Ticket Holder', $registration->fresh()->guest_name);
        $this->assertSame('second-holder@example.test', $registration->fresh()->guest_email);
        $sessionB = $passB->getCookie($sessionCookie)->getValue();
        $this->assertNotSame($sessionA, $sessionB);
        $this->assertTrue(ctype_alnum($sessionB));
        $this->assertSame(40, strlen($sessionB));
        $passBPage = $this->withCookie($sessionCookie, $sessionB)
            ->get($ticketUrl)
            ->assertOk();
        preg_match('/id="redemption-code">([a-f0-9]{64})<\/code>/', $passBPage->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $this->assertNotSame($redemptionCodeA, $matches[1]);
        $this->get($pdfUrl)->assertOk();
        $this->assertSame(2, $registration->ticket->fresh()->credential_version);

        $this->withCookie($sessionCookie, $sessionA)
            ->get($checkoutUrl)
            ->assertOk();
        $this->assertSame($sessionA, $this->app['session.store']->getId());
        $this->get($ticketUrl)->assertNotFound();
        $this->get($pdfUrl)->assertNotFound();

        $this->post(route('public.checkout.process', $order->public_id), [
            'attempt_key' => $attemptKey,
            'status' => 'failed',
        ])->assertRedirect();
        $this->get($ticketUrl)->assertNotFound();
        $this->get($pdfUrl)->assertNotFound();
    }

    public function test_expired_interrupted_checkout_handoff_does_not_restore_the_redemption_code(): void
    {
        $order = $this->startPaidCheckout();
        $attemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_WALLET,
            'attempt_key' => $attemptKey,
        ]);
        $this->app->instance(SimulatedPaymentProcessor::class, new class extends SimulatedPaymentProcessor
        {
            public function process(PaymentAttempt $attempt): string
            {
                return PaymentAttempt::STATUS_SUCCESSFUL;
            }
        });
        $result = app(ProcessSimulatedPayment::class)->execute($order->id, $attemptKey);
        $this->assertIsString($result['redemption_code']);
        $order->forceFill(['redemption_code_expires_at' => now()->subSecond()])->save();

        $this->get(route('public.checkout.show', $order->public_id))->assertOk();
        $this->get(route('public.tickets.show', $result['registration']->id))
            ->assertOk()
            ->assertDontSee($result['redemption_code']);

        $this->assertNull($order->fresh()->redemption_code_ciphertext);
        $this->assertNull($order->fresh()->redemption_code_expires_at);
    }

    public function test_student_eligibility_revoked_before_confirmation_fails_without_issuing_a_ticket(): void
    {
        config(['services.firebase.student_email_domain' => 'student.mseuf.edu.ph']);
        $this->event->update(['simulated_payments_enabled' => true]);
        $category = $this->category($this->event, 'Student Admission', priceMinor: 12500, isDefault: true);
        $category->update(['eligibility' => TicketCategory::ELIGIBILITY_STUDENT]);
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'verified@student.mseuf.edu.ph',
            'firebase_uid' => 'firebase-paid-checkout-student',
            'email_verified_at' => now(),
        ]);
        $idempotencyKey = $this->registrationFormKey();

        $this->actingAs($student)->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $category->id,
            'idempotency_key' => $idempotencyKey,
        ])->assertRedirect(route('public.checkout.show', CheckoutOrder::query()->sole()->public_id));

        $order = CheckoutOrder::query()->sole();
        $attemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_WALLET,
            'attempt_key' => $attemptKey,
        ]);

        DB::table('users')->where('id', $student->id)->update([
            'firebase_uid' => null,
            'email_verified_at' => null,
        ]);
        $this->app->instance(SimulatedPaymentProcessor::class, new class extends SimulatedPaymentProcessor
        {
            public function process(PaymentAttempt $attempt): string
            {
                return PaymentAttempt::STATUS_SUCCESSFUL;
            }
        });

        $this->post(route('public.checkout.process', $order->public_id), ['attempt_key' => $attemptKey])
            ->assertRedirect(route('public.checkout.show', $order->public_id));

        $this->assertSame(CheckoutOrder::STATUS_FAILED, $order->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_FAILED, $order->attempts()->sole()->status);
        $this->assertSame('reservation_unavailable', $order->attempts()->sole()->outcome_code);
        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_expired_checkout_releases_its_reservation_and_marks_pending_attempt_expired(): void
    {
        $this->event->update(['capacity' => 1]);
        $start = now();
        Carbon::setTestNow($start);

        try {
            $order = $this->startPaidCheckout();
            $attemptKey = (string) Str::uuid();
            $this->post(route('public.checkout.attempt', $order->public_id), [
                'payment_method' => PaymentAttempt::METHOD_WALLET,
                'attempt_key' => $attemptKey,
            ]);

            Carbon::setTestNow($start->copy()->addMinutes(11));
            $this->get(route('public.checkout.show', $order->public_id))->assertOk();

            $this->assertSame(CheckoutOrder::STATUS_EXPIRED, $order->fresh()->status);
            $this->assertSame(PaymentAttempt::STATUS_EXPIRED, $order->attempts()->sole()->status);

            $freeCategory = $this->category($this->event, 'Free General Admission');
            $this->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $freeCategory->id,
                'guest_name' => 'Next Guest',
                'guest_email' => 'next@example.test',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertRedirect();

            $this->assertSame(1, Registration::count());
            $this->assertSame(1, Ticket::count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revoking_event_authorization_cancels_pending_attempts_and_frees_capacity(): void
    {
        $this->event->update(['capacity' => 1, 'simulated_payments_enabled' => true]);
        $order = $this->startPaidCheckout();
        $attemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_WALLET,
            'attempt_key' => $attemptKey,
        ]);

        $manager = User::factory()->create(['role' => User::ROLE_EVENT_MANAGER]);
        EventManagerAssignment::create([
            'event_id' => $this->event->id,
            'user_id' => $manager->id,
            'assigned_by_user_id' => $this->event->organizer_id,
        ]);

        $this->actingAs($manager)
            ->post(route('organizer.events.simulated-payments.update', $this->event), ['enabled' => false])
            ->assertRedirect(route('organizer.events.show', $this->event));

        $this->assertSame(CheckoutOrder::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED, $order->attempts()->sole()->status);

        $freeCategory = $this->category($this->event, 'Free General Admission');
        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $freeCategory->id,
            'guest_name' => 'Next Guest',
            'guest_email' => 'next@example.test',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $this->assertSame(1, Registration::count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_event_capacity_cannot_be_lowered_below_active_paid_reservations(): void
    {
        $this->event->update(['capacity' => 3]);
        $this->startPaidCheckout('first@example.test');
        $this->startPaidCheckout('second@example.test');

        $response = $this->actingAs($this->event->organizer)
            ->put(route('organizer.events.update', $this->event), [
                'venue_id' => $this->event->venue_id,
                'title' => $this->event->title,
                'description' => $this->event->description,
                'capacity' => 1,
                'registration_opens_at' => $this->event->registration_opens_at->toDateTimeString(),
                'registration_closes_at' => $this->event->registration_closes_at->toDateTimeString(),
                'starts_at' => $this->event->starts_at->toDateTimeString(),
                'ends_at' => $this->event->ends_at->toDateTimeString(),
                're_entry_allowed' => false,
                'identity_verification_level' => Event::IDENTITY_STANDARD,
                'ticket_transfers_allowed' => false,
            ]);

        $response->assertSessionHasErrors('capacity');
        $this->assertSame(3, $this->event->fresh()->capacity);
    }

    public function test_category_price_and_eligibility_cannot_change_while_checkout_is_reserved(): void
    {
        $order = $this->startPaidCheckout();
        $category = $order->ticketCategory;

        $this->actingAs($this->event->organizer)
            ->put(route('organizer.events.ticket-categories.update', [$this->event, $category]), [
                'name' => 'Paid Admission',
                'price_pesos' => '130.00',
                'capacity' => 2,
                'eligibility' => TicketCategory::ELIGIBILITY_PUBLIC,
            ])
            ->assertSessionHasErrors('category');

        $this->assertSame(12500, $category->fresh()->price_minor);
    }

    public function test_paid_student_category_requires_verified_institutional_identity(): void
    {
        config(['services.firebase.student_email_domain' => 'student.mseuf.edu.ph']);
        $this->event->update([
            'registration_eligibility' => Event::ELIGIBILITY_STUDENT,
            'simulated_payments_enabled' => true,
        ]);
        $category = $this->category($this->event, 'Student Admission', priceMinor: 12500, isDefault: true);
        $category->update(['eligibility' => TicketCategory::ELIGIBILITY_STUDENT]);
        $idempotencyKey = $this->registrationFormKey();
        $payload = [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Attempted Guest',
            'guest_email' => 'attempt@example.test',
            'idempotency_key' => $idempotencyKey,
        ];

        $this->post(route('public.events.register.store', $this->event->slug), $payload)
            ->assertRedirect(route('student.auth'));

        $unverified = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'unverified@student.mseuf.edu.ph',
            'firebase_uid' => null,
            'email_verified_at' => null,
        ]);
        $this->actingAs($unverified)
            ->post(route('public.events.register.store', $this->event->slug), $payload)
            ->assertSessionHasErrors('ticket_category_id');

        $verified = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'verified@student.mseuf.edu.ph',
            'firebase_uid' => 'firebase-verified-checkout',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($verified)
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
                'idempotency_key' => $this->registrationFormKey(),
            ])
            ->assertRedirect();

        $order = CheckoutOrder::query()->sole();
        $this->assertSame($verified->id, $order->user_id);
        $this->assertSame('student', $order->attendee_type);
        $this->assertNull($order->guest_email);
        $this->assertNull($order->guest_name);
    }

    public function test_failed_attempt_can_be_retried_without_releasing_its_seat_or_losing_history(): void
    {
        $this->event->update(['capacity' => 1]);
        $order = $this->startPaidCheckout();
        $firstAttemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_WALLET,
            'attempt_key' => $firstAttemptKey,
        ]);

        $this->app->instance(SimulatedPaymentProcessor::class, new class extends SimulatedPaymentProcessor
        {
            public function process(PaymentAttempt $attempt): string
            {
                return PaymentAttempt::STATUS_FAILED;
            }
        });
        $this->post(route('public.checkout.process', $order->public_id), ['attempt_key' => $firstAttemptKey]);

        $freeCategory = $this->category($this->event, 'Free General Admission');
        $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $freeCategory->id,
                'guest_name' => 'Next Guest',
                'guest_email' => 'next@example.test',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('capacity');

        $secondAttemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_CARD,
            'attempt_key' => $secondAttemptKey,
        ]);
        $this->app->instance(SimulatedPaymentProcessor::class, new class extends SimulatedPaymentProcessor
        {
            public function process(PaymentAttempt $attempt): string
            {
                return PaymentAttempt::STATUS_SUCCESSFUL;
            }
        });
        $this->post(route('public.checkout.process', $order->public_id), ['attempt_key' => $secondAttemptKey]);

        $this->assertSame(CheckoutOrder::STATUS_SUCCESSFUL, $order->fresh()->status);
        $this->assertSame([
            PaymentAttempt::STATUS_FAILED,
            PaymentAttempt::STATUS_SUCCESSFUL,
        ], $order->attempts()->orderBy('attempt_number')->pluck('status')->all());
        $this->assertSame(1, Registration::count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_guest_can_cancel_pending_checkout_and_release_capacity(): void
    {
        $this->event->update(['capacity' => 1]);
        $order = $this->startPaidCheckout();
        $attemptKey = (string) Str::uuid();
        $this->post(route('public.checkout.attempt', $order->public_id), [
            'payment_method' => PaymentAttempt::METHOD_WALLET,
            'attempt_key' => $attemptKey,
        ]);

        $this->post(route('public.checkout.cancel', $order->public_id))
            ->assertRedirect(route('public.checkout.show', $order->public_id));

        $this->assertSame(CheckoutOrder::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(PaymentAttempt::STATUS_CANCELLED, $order->attempts()->sole()->status);

        $freeCategory = $this->category($this->event, 'Free General Admission');
        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $freeCategory->id,
            'guest_name' => 'Next Guest',
            'guest_email' => 'next@example.test',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $this->assertSame(1, Registration::count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_checkout_form_idempotency_key_cannot_be_replayed_from_another_session(): void
    {
        $this->event->update(['simulated_payments_enabled' => true]);
        $category = $this->category($this->event, 'Paid Admission', priceMinor: 12500, isDefault: true);
        $idempotencyKey = $this->registrationFormKey();
        $this->flushSession();

        $this->from(route('public.events.register', $this->event->slug))
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
                'guest_name' => 'Taylor Guest',
                'guest_email' => 'taylor@example.test',
                'idempotency_key' => $idempotencyKey,
            ])
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(0, CheckoutOrder::count());
        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    private function startPaidCheckout(string $email = 'paid@example.test'): CheckoutOrder
    {
        $this->event->update(['simulated_payments_enabled' => true]);
        $category = TicketCategory::query()->where('event_id', $this->event->id)->where('name', 'Paid Admission')->first();
        $category ??= $this->category($this->event, 'Paid Admission', priceMinor: 12500, capacity: 2, isDefault: true);
        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Paid Guest',
            'guest_email' => $email,
            'idempotency_key' => $this->registrationFormKey(),
        ]);

        return CheckoutOrder::query()->latest('id')->firstOrFail();
    }

    private function registrationFormKey(): string
    {
        $response = $this->get(route('public.events.register', $this->event->slug))->assertOk();
        preg_match('/name="idempotency_key" value="([^"]+)"/', $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function category(
        Event $event,
        string $name,
        int $priceMinor = 0,
        ?int $capacity = null,
        bool $isDefault = false,
    ): TicketCategory {
        if ($isDefault) {
            $event->ticketCategories()->update(['is_default' => false]);
        }

        return TicketCategory::create([
            'event_id' => $event->id,
            'name' => $name,
            'price_minor' => $priceMinor,
            'currency' => 'PHP',
            'capacity' => $capacity,
            'eligibility' => TicketCategory::ELIGIBILITY_PUBLIC,
            'is_active' => true,
            'is_default' => $isDefault,
        ]);
    }
}
