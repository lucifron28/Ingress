<?php

namespace Tests\Feature;

use App\Actions\AdmitTicket;
use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\EventManagerAssignment;
use App\Models\EventStaff;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Models\Venue;
use App\Services\Tickets\TicketPdfGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuestTicketTransferTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private User $organizer;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->flush();
        config(['ingress.mode' => 'cloud']);
        config(['services.firebase.student_email_domain' => 'student.mseuf.edu.ph']);

        $this->organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create([
            'name' => 'Guest Transfer Hall',
            'code' => 'GUEST-TRANSFER',
            'location' => 'Main Campus',
            'default_capacity' => 100,
        ]);
        $this->event = Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $venue->id,
            'title' => 'Guest Transfer Event',
            'slug' => 'guest-transfer-event',
            'description' => 'A public event for guest ticket transfer tests.',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
            'ticket_transfers_allowed' => true,
        ]);
    }

    public function test_guest_checkout_shows_a_private_redemption_code_once_and_keeps_only_its_hash(): void
    {
        $category = $this->event->ticketCategories()->firstOrFail();
        $checkout = $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Taylor Guest',
            'guest_email' => 'taylor@example.test',
        ]);
        $registration = Registration::query()->sole();
        $ticket = Ticket::query()->sole();

        $checkout->assertRedirect(route('public.tickets.show', $registration));
        $handoffs = $this->app['session.store']->get('ticket_redemption_handoffs', []);
        $handoff = $handoffs[(string) $registration->id] ?? null;
        $this->assertIsArray($handoff);
        $sessionCode = Crypt::decryptString($handoff['ciphertext']);
        $this->assertStringNotContainsString($sessionCode, serialize($handoffs));

        $pass = $this->get(route('public.tickets.show', $registration));
        $pass->assertOk()
            ->assertSee('Possession of this code grants ticket transfer control.')
            ->assertSee('The code is shown once. Save it separately from the admission QR.')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        preg_match('/id="redemption-code">([a-f0-9]{64})<\/code>/', $pass->getContent(), $matches);
        $this->assertSame(2, count($matches));
        $redemptionCode = $matches[1];
        $this->assertSame($sessionCode, $redemptionCode);
        $this->assertSame(hash('sha256', $redemptionCode), DB::table('tickets')->where('id', $ticket->id)->value('redemption_hash'));
        $this->assertStringNotContainsString($redemptionCode, (string) $ticket->fresh()->getQrPayload());

        $pdf = $this->get(route('public.tickets.pdf', $registration));
        $pdf->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString($redemptionCode, $pdf->getContent());

        $this->get(route('public.tickets.show', $registration))
            ->assertOk()
            ->assertDontSee($redemptionCode)
            ->assertDontSee('Possession of this code grants ticket transfer control.');

        $this->get(route('public.tickets.redeem'))
            ->assertOk()
            ->assertDontSee($redemptionCode)
            ->assertSee('name="guest_name"', false)
            ->assertSee('name="guest_email"', false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_guest_can_transfer_the_ticket_from_person_to_person_by_rotating_both_codes(): void
    {
        [$registration, $ticket, $codeA] = $this->checkoutGuest('first-holder@example.test');
        $qrA = $ticket->getQrPayload();
        $secretA = explode(':', $qrA)[3];

        $this->flushSession();
        $this->post(route('public.tickets.redeem.store'), [
            'redemption_code' => $codeA,
            'guest_name' => 'Second Ticket Holder',
            'guest_email' => 'second-holder@example.test',
        ])
            ->assertRedirect(route('public.tickets.show', $registration));
        $passB = $this->get(route('public.tickets.show', $registration))->assertOk();
        $codeB = $this->redemptionCodeFrom($passB->getContent());
        $passB->assertSee('Second Ticket Holder')->assertDontSee('Original Ticket Holder');
        $registrationB = $registration->fresh();
        $this->assertSame('Second Ticket Holder', $registrationB->guest_name);
        $this->assertSame('second-holder@example.test', $registrationB->guest_email);

        $pdfB = $this->get(route('public.tickets.pdf', $registration))->assertOk();
        $this->assertStringContainsString('Second Ticket Holder', app(TicketPdfGenerator::class)->renderHtml(
            $registrationB->load(['event.venue', 'ticketCategory', 'ticket']),
            $ticket->fresh()->getQrPayload(),
        ));

        $ticketB = $ticket->fresh();
        $qrB = $ticketB->getQrPayload();
        $this->assertSame(2, $ticketB->credential_version);
        $this->assertNotSame($qrA, $qrB);
        $this->assertFalse($ticketB->verifySecret($secretA));
        $this->assertNotSame(hash('sha256', $codeA), $ticketB->redemption_hash);
        $this->assertStringNotContainsString($codeA, $passB->getContent());
        $this->assertDatabaseHas('guest_ticket_credential_rotations', [
            'ticket_id' => $ticket->id,
            'from_credential_version' => 1,
            'to_credential_version' => 2,
            'kind' => 'redeemed',
            'actor_user_id' => null,
        ]);
        $this->get(route('public.tickets.pdf', $registration))->assertOk();

        $this->flushSession();
        $this->post(route('public.tickets.redeem.store'), [
            'redemption_code' => $codeA,
            'guest_name' => 'Unauthorized Reuse',
            'guest_email' => 'reuse@example.test',
        ])
            ->assertRedirect(route('public.tickets.redeem'))
            ->assertSessionHasErrors('redemption_code')
            ->assertSessionMissing('_old_input');
        $this->assertSame(2, $ticket->fresh()->credential_version);

        $this->flushSession();
        $this->post(route('public.tickets.redeem.store'), [
            'redemption_code' => $codeB,
            'guest_name' => 'Third Ticket Holder',
            'guest_email' => 'third-holder@example.test',
        ])
            ->assertRedirect(route('public.tickets.show', $registration));
        $passC = $this->get(route('public.tickets.show', $registration))->assertOk();
        $codeC = $this->redemptionCodeFrom($passC->getContent());
        $passC->assertSee('Third Ticket Holder')->assertDontSee('Second Ticket Holder');
        $registrationC = $registration->fresh();
        $this->assertSame('Third Ticket Holder', $registrationC->guest_name);
        $this->assertSame('third-holder@example.test', $registrationC->guest_email);
        $this->get(route('public.tickets.pdf', $registration))->assertOk();
        $this->assertStringContainsString('Third Ticket Holder', app(TicketPdfGenerator::class)->renderHtml(
            $registrationC->load(['event.venue', 'ticketCategory', 'ticket']),
            $ticket->fresh()->getQrPayload(),
        ));

        $ticketC = $ticket->fresh();
        $this->assertSame(3, $ticketC->credential_version);
        $this->assertNotSame($qrB, $ticketC->getQrPayload());
        $this->assertNotSame($codeB, $codeC);
        $this->assertSame(hash('sha256', $codeC), $ticketC->redemption_hash);
        $this->assertDatabaseCount('guest_ticket_credential_rotations', 3);

        $this->event->update(['identity_verification_level' => Event::IDENTITY_CHECK]);
        $gate = AccessPoint::create([
            'event_id' => $this->event->id,
            'name' => 'North Gate',
            'code' => 'NORTH',
            'is_active' => true,
        ]);
        $scanner = User::factory()->create(['role' => User::ROLE_STAFF]);
        EventStaff::create([
            'event_id' => $this->event->id,
            'user_id' => $scanner->id,
            'access_point_id' => $gate->id,
        ]);
        config(['ingress.mode' => 'legacy']);

        $preview = app(AdmitTicket::class)->preview(
            $scanner,
            $this->event->id,
            $gate->id,
            $ticketC->getQrPayload(),
        );
        $this->assertSame(200, $preview['http_code']);
        $this->assertSame('Third Ticket Holder', $preview['ticket']['attendee_name']);
        $this->assertNull($preview['ticket']['student_id']);
        $this->assertSame(Event::IDENTITY_CHECK, $preview['ticket']['identity_verification_level']);
        $this->assertSame('Compare the attendee with an approved photo ID before confirming.', $preview['detail']);
    }

    public function test_guest_redemption_rejects_invalid_attendee_details_without_flashing_the_code(): void
    {
        [$registration, $ticket, $code] = $this->checkoutGuest('invalid-details@example.test');
        $this->flushSession();

        $this->post(route('public.tickets.redeem.store'), [
            'redemption_code' => $code,
            'guest_name' => "\0",
            'guest_email' => 'not-an-email',
        ])
            ->assertRedirect(route('public.tickets.redeem'))
            ->assertSessionHasErrors(['guest_name', 'guest_email'])
            ->assertSessionMissing('_old_input');

        $this->assertSame('Original Ticket Holder', $registration->fresh()->guest_name);
        $this->assertSame('invalid-details@example.test', $registration->fresh()->guest_email);
        $this->assertSame(1, $ticket->fresh()->credential_version);
        $this->assertSame(hash('sha256', $code), $ticket->fresh()->redemption_hash);
        $this->assertDatabaseCount('guest_ticket_credential_rotations', 1);
    }

    public function test_guest_redemption_rejects_an_email_already_used_for_another_event_registration(): void
    {
        [$registration, $ticket, $code] = $this->checkoutGuest('first-holder@example.test');
        $this->checkoutGuest('second-holder@example.test');
        $this->flushSession();

        $this->post(route('public.tickets.redeem.store'), [
            'redemption_code' => $code,
            'guest_name' => 'Second Ticket Holder',
            'guest_email' => 'SECOND-HOLDER@example.test',
        ])
            ->assertRedirect(route('public.tickets.redeem'))
            ->assertSessionHasErrors('redemption_code')
            ->assertSessionMissing('_old_input');

        $this->assertSame('Original Ticket Holder', $registration->fresh()->guest_name);
        $this->assertSame('first-holder@example.test', $registration->fresh()->guest_email);
        $this->assertSame(1, $ticket->fresh()->credential_version);
        $this->assertSame(hash('sha256', $code), $ticket->fresh()->redemption_hash);
    }

    public function test_snapshot_exports_the_current_qr_hash_without_the_guest_redemption_hash(): void
    {
        [$registration, $ticket, $redemptionCode] = $this->checkoutGuest('snapshot-holder@example.test');

        $this->flushSession();
        $this->post(route('public.tickets.redeem.store'), [
            'redemption_code' => $redemptionCode,
            'guest_name' => 'Snapshot Ticket Holder',
            'guest_email' => 'snapshot-recipient@example.test',
        ])->assertRedirect(route('public.tickets.show', $registration));

        $this->actingAs($this->organizer, 'web')
            ->post(route('organizer.events.registration.close', $this->event))
            ->assertRedirect();
        $this->post(route('organizer.events.snapshot.prepare', $this->event))->assertRedirect();

        $snapshot = DB::table('event_snapshots')->where('event_id', $this->event->id)->firstOrFail();
        $json = Crypt::decryptString($snapshot->payload_ciphertext);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $snapshotTicket = $payload['registrations'][0]['ticket'];

        $this->assertSame($ticket->fresh()->qr_hash, $snapshotTicket['qr_hash']);
        $this->assertNotSame($ticket->qr_hash, $snapshotTicket['qr_hash']);
        $this->assertArrayNotHasKey('redemption_hash', $snapshotTicket);
        $this->assertStringNotContainsString($redemptionCode, $json);
        $this->assertSame($registration->id, $payload['registrations'][0]['id']);
        $this->assertSame('Snapshot Ticket Holder', $payload['registrations'][0]['attendee']['name']);
        $this->assertSame('snapshot-recipient@example.test', $payload['registrations'][0]['attendee']['email']);
    }

    public function test_student_restricted_tickets_do_not_receive_guest_redemption_codes(): void
    {
        $category = $this->event->ticketCategories()->firstOrFail();
        $category->update(['eligibility' => TicketCategory::ELIGIBILITY_STUDENT]);
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'verified@student.mseuf.edu.ph',
            'firebase_uid' => 'firebase-verified-student',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($student, 'web')
            ->post(route('public.events.register.store', $this->event->slug), [
                'ticket_category_id' => $category->id,
            ])
            ->assertRedirect();

        $registration = Registration::query()->sole();
        $ticket = Ticket::query()->sole();
        $this->assertSame($student->id, $registration->user_id);
        $this->assertNull($ticket->redemption_hash);
        $this->assertDatabaseCount('guest_ticket_credential_rotations', 0);
        $this->get(route('public.tickets.show', $registration))
            ->assertOk()
            ->assertDontSee('Possession of this code grants ticket transfer control.');
    }

    public function test_redemption_rejects_snapshot_cancellation_expiry_revoke_admission_and_restricted_eligibility(): void
    {
        [$registration, $ticket, $code] = $this->checkoutGuest('boundary-holder@example.test');
        $endsAt = $this->event->ends_at->copy();

        $this->event->forceFill(['snapshot_prepared_at' => now()])->save();
        $this->assertRedemptionRejected($code);
        $this->event->forceFill(['snapshot_prepared_at' => null])->save();

        $this->event->update(['status' => Event::STATUS_CANCELLED]);
        $this->assertRedemptionRejected($code);
        $this->event->update(['status' => Event::STATUS_PUBLISHED]);

        $this->event->update(['ends_at' => now()->subMinute()]);
        $this->assertRedemptionRejected($code);
        $this->event->update(['ends_at' => $endsAt]);

        $ticket->update(['status' => Ticket::STATUS_REVOKED]);
        $this->assertRedemptionRejected($code);
        $ticket->update(['status' => Ticket::STATUS_ACTIVE]);

        $registration->update(['status' => Registration::STATUS_CANCELLED]);
        $this->assertRedemptionRejected($code);
        $registration->update(['status' => Registration::STATUS_CONFIRMED]);

        $registration->ticketCategory->update(['eligibility' => TicketCategory::ELIGIBILITY_STUDENT]);
        $this->assertRedemptionRejected($code);
        $registration->ticketCategory->update(['eligibility' => TicketCategory::ELIGIBILITY_PUBLIC]);

        $scanner = User::factory()->create(['role' => User::ROLE_STAFF]);
        $gate = AccessPoint::create([
            'event_id' => $this->event->id,
            'name' => 'North Gate',
            'code' => 'NORTH',
            'is_active' => true,
        ]);
        Admission::create([
            'ticket_id' => $ticket->id,
            'access_point_id' => $gate->id,
            'scanned_by_user_id' => $scanner->id,
            'client_scan_id' => 'guest-boundary-admission',
            'credential_version' => 1,
            'direction' => Admission::DIRECTION_ENTRY,
            'admitted_at' => now(),
        ]);
        $this->assertRedemptionRejected($code);

        $this->assertSame(1, $ticket->fresh()->credential_version);
        $this->assertSame(hash('sha256', $code), $ticket->fresh()->redemption_hash);
        $this->assertDatabaseCount('guest_ticket_credential_rotations', 1);
    }

    public function test_only_an_assigned_event_manager_can_recover_and_reissue_a_guest_code(): void
    {
        [$registration, $ticket, $oldCode] = $this->checkoutGuest('recovery-holder@example.test');
        $manager = User::factory()->create(['role' => User::ROLE_STUDENT, 'email' => 'manager@example.test']);
        EventManagerAssignment::create([
            'event_id' => $this->event->id,
            'user_id' => $manager->id,
            'assigned_by_user_id' => $this->organizer->id,
        ]);
        $recoveryUrl = route('organizer.events.guests.recover', [$this->event, $registration]);

        $this->actingAs($manager, 'web')->get(route('organizer.events.show', $this->event))
            ->assertOk()
            ->assertSee('Rotate QR and reissue transfer code')
            ->assertSee('identity outside Ingress');

        $this->actingAs($manager, 'web')->post($recoveryUrl)
            ->assertSessionHasErrors('identity_verified');
        $this->assertSame(1, $ticket->fresh()->credential_version);

        $oldQrSecret = explode(':', (string) $ticket->getQrPayload())[3];
        $this->post($recoveryUrl, ['identity_verified' => '1'])
            ->assertRedirect(route('organizer.events.guest-pass', [$this->event, $registration]));

        $reissuedPass = $this->get(route('organizer.events.guest-pass', [$this->event, $registration]))->assertOk();
        $newCode = $this->redemptionCodeFrom($reissuedPass->getContent());
        $rotatedTicket = $ticket->fresh();
        $this->assertSame(2, $rotatedTicket->credential_version);
        $this->assertFalse($rotatedTicket->verifySecret($oldQrSecret));
        $this->assertNotSame($oldCode, $newCode);
        $this->assertSame(hash('sha256', $newCode), $rotatedTicket->redemption_hash);
        $this->assertDatabaseHas('guest_ticket_credential_rotations', [
            'ticket_id' => $ticket->id,
            'from_credential_version' => 1,
            'to_credential_version' => 2,
            'kind' => 'recovery',
            'actor_user_id' => $manager->id,
        ]);
        $this->get(route('organizer.events.guest-pass', [$this->event, $registration]))
            ->assertOk()
            ->assertDontSee($newCode);

        $this->event->forceFill(['snapshot_prepared_at' => now()])->save();
        $this->post($recoveryUrl, ['identity_verified' => '1'])
            ->assertSessionHasErrors('guest');
        $this->assertSame(2, $ticket->fresh()->credential_version);
        $this->assertDatabaseCount('guest_ticket_credential_rotations', 2);

        $this->flushSession();
        $this->post(route('public.tickets.redeem.store'), ['redemption_code' => $oldCode])
            ->assertRedirect(route('public.tickets.redeem'));
        $this->assertSame(2, $ticket->fresh()->credential_version);
    }

    public function test_event_manager_cannot_recover_a_guest_ticket_for_an_unassigned_event(): void
    {
        [$registration, $ticket] = $this->checkoutGuest('scoped-holder@example.test');
        $otherManager = User::factory()->create(['role' => User::ROLE_STUDENT, 'email' => 'other-manager@example.test']);
        $otherEvent = Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $this->event->venue_id,
            'title' => 'Other Managed Event',
            'slug' => 'other-managed-event',
            'capacity' => 10,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);
        EventManagerAssignment::create([
            'event_id' => $otherEvent->id,
            'user_id' => $otherManager->id,
            'assigned_by_user_id' => $this->organizer->id,
        ]);

        $this->actingAs($otherManager, 'web')
            ->post(route('organizer.events.guests.recover', [$this->event, $registration]), ['identity_verified' => '1'])
            ->assertForbidden();

        $this->assertSame(1, $ticket->fresh()->credential_version);
        $this->assertDatabaseCount('guest_ticket_credential_rotations', 1);
    }

    public function test_organizer_issued_guest_pass_shows_its_transfer_code_once(): void
    {
        $response = $this->actingAs($this->organizer, 'web')->post(route('organizer.events.guests.store', $this->event), [
            'guest_name' => 'Organizer Guest',
            'guest_email' => 'organizer-guest@example.test',
            'attendee_type' => 'guest',
        ]);
        $registration = Registration::query()->sole();
        $ticket = Ticket::query()->sole();
        $response->assertRedirect(route('organizer.events.guest-pass', [$this->event, $registration]));

        $pass = $this->get(route('organizer.events.guest-pass', [$this->event, $registration]))->assertOk();
        $code = $this->redemptionCodeFrom($pass->getContent());
        $this->assertSame(hash('sha256', $code), $ticket->fresh()->redemption_hash);
        $this->get(route('organizer.events.guest-pass', [$this->event, $registration]))
            ->assertOk()
            ->assertDontSee($code);
    }

    public function test_redemption_attempts_are_rate_limited_by_ip(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post(route('public.tickets.redeem.store'), ['redemption_code' => 'invalid-code'])
                ->assertRedirect(route('public.tickets.redeem'));
        }

        $this->post(route('public.tickets.redeem.store'), ['redemption_code' => 'invalid-code'])
            ->assertTooManyRequests();
    }

    /**
     * @return array{0: Registration, 1: Ticket, 2: string}
     */
    private function checkoutGuest(string $email): array
    {
        $category = $this->event->ticketCategories()->firstOrFail();
        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Original Ticket Holder',
            'guest_email' => $email,
        ])->assertRedirect();

        $registration = Registration::query()->where('guest_email', $email)->firstOrFail();
        $ticket = Ticket::query()->where('registration_id', $registration->id)->firstOrFail();
        $pass = $this->get(route('public.tickets.show', $registration))->assertOk();

        return [$registration, $ticket, $this->redemptionCodeFrom($pass->getContent())];
    }

    private function redemptionCodeFrom(string $content): string
    {
        preg_match('/id="redemption-code">([a-f0-9]{64})<\/code>/', $content, $matches);
        $this->assertSame(2, count($matches));

        return $matches[1];
    }

    private function assertRedemptionRejected(string $code): void
    {
        $this->flushSession();
        $this->post(route('public.tickets.redeem.store'), [
            'redemption_code' => $code,
            'guest_name' => 'Attempted Holder',
            'guest_email' => 'attempted-holder@example.test',
        ])
            ->assertRedirect(route('public.tickets.redeem'))
            ->assertSessionHasErrors('redemption_code');
    }
}
