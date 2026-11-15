<?php

namespace Tests\Feature;

use App\Actions\RegisterAttendeeForEvent;
use App\Actions\RegisterForEvent;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use App\Services\Tickets\TicketPdfGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TicketPdfDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->flush();
        config(['ingress.mode' => 'cloud']);

        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->venue = Venue::create([
            'name' => 'PDF Test Hall',
            'code' => 'PDF-HALL',
            'location' => 'Main Campus',
            'default_capacity' => 100,
        ]);
        $this->event = Event::create([
            'organizer_id' => $organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'PDF Delivery Event',
            'slug' => 'pdf-delivery-event',
            'description' => 'A test event for downloadable tickets.',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
        ]);
    }

    public function test_guest_can_download_a_private_pdf_during_the_short_lived_checkout_handoff(): void
    {
        $response = $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $this->event->ticketCategories()->firstOrFail()->id,
            'guest_name' => 'Ticket Holder',
            'guest_email' => 'holder@example.test',
        ]);
        $registration = Registration::query()->sole();
        $ticket = Ticket::query()->sole();

        $response->assertRedirect(route('public.tickets.show', $registration));

        $html = app(TicketPdfGenerator::class)->renderHtml($registration->load(['event.venue', 'ticketCategory', 'ticket']), $ticket->getQrPayload());
        $localStart = $registration->event->starts_at->copy()->setTimezone('Asia/Manila')->format('l, F j, Y · g:i A');
        $this->assertStringContainsString('PDF Delivery Event', $html);
        $this->assertStringContainsString('Ticket Holder', $html);
        $this->assertStringContainsString('General Admission', $html);
        $this->assertStringContainsString($ticket->public_id, $html);
        $this->assertStringContainsString($localStart, $html);
        $this->assertStringContainsString('PHT', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringNotContainsString('holder@example.test', $html);
        $this->assertMatchesRegularExpression('/src="data:image\/svg\+xml;base64,([^"]+)"/', $html);
        preg_match('/src="data:image\/svg\+xml;base64,([^"]+)"/', $html, $matches);
        $svg = base64_decode($matches[1], true);
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString((string) $ticket->getQrPayload(), $svg);

        $download = $this->get(route('public.tickets.pdf', $registration));
        $download->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith('%PDF-', $download->getContent());
        $download->assertHeader('Content-Disposition', 'attachment; filename="Ingress-ticket-'.$ticket->public_id.'.pdf"');
        $this->assertStringNotContainsString('holder@example.test', (string) $download->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => Ticket::STATUS_ACTIVE]);
        $this->get(route('public.tickets.show', $registration))
            ->assertOk()
            ->assertSee($localStart)
            ->assertSee('PHT');

        $this->travel(16)->minutes();
        $this->get(route('public.tickets.pdf', $registration))->assertNotFound();
        $this->get(route('public.tickets.show', $registration))->assertOk();
        $this->travelBack();
    }

    public function test_pdf_template_escapes_event_markup_and_shows_only_the_simulated_payment_label(): void
    {
        $student = $this->student('markup@student.mseuf.edu.ph');
        $registration = $this->createStudentRegistration($student);
        $registration->load(['event.venue', 'ticketCategory', 'ticket']);
        $registration->event->update(['title' => 'Event <img src="http://127.0.0.1:1/blocked">']);
        $registration->ticketCategory->update(['price_minor' => 2500]);

        $html = app(TicketPdfGenerator::class)->renderHtml($registration->fresh(['event.venue', 'ticketCategory', 'ticket']), $registration->ticket->getQrPayload());

        $this->assertStringContainsString('&lt;img src=&quot;http://127.0.0.1:1/blocked&quot;&gt;', $html);
        $this->assertStringNotContainsString('<img src="http://127.0.0.1:1/blocked">', $html);
        $this->assertStringContainsString('SIMULATED PAYMENT — NO REAL MONEY IS CHARGED', $html);
    }

    public function test_pdf_download_endpoint_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->get('/tickets/999999/pdf')->assertNotFound();
        }

        $this->get('/tickets/999999/pdf')->assertTooManyRequests();
    }

    public function test_pdf_renderer_errors_are_generic_and_do_not_log_private_details(): void
    {
        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $this->event->ticketCategories()->firstOrFail()->id,
            'guest_name' => 'Ticket Holder',
            'guest_email' => 'holder@example.test',
        ]);
        $registration = Registration::query()->sole();

        $generator = Mockery::mock(TicketPdfGenerator::class)->makePartial();
        $generator->shouldReceive('renderHtml')
            ->once()
            ->andThrow(new HttpException(500, 'private renderer diagnostic'));
        app()->instance(TicketPdfGenerator::class, $generator);
        Log::spy();

        $this->get(route('public.tickets.pdf', $registration))
            ->assertStatus(503)
            ->assertDontSee('private renderer diagnostic');

        Log::shouldHaveReceived('error')->once()->with('Ticket PDF generation failed.');
    }

    public function test_guest_pdf_handoff_does_not_grant_access_to_another_registration(): void
    {
        $this->post(route('public.events.register.store', $this->event->slug), [
            'ticket_category_id' => $this->event->ticketCategories()->firstOrFail()->id,
            'guest_name' => 'First Holder',
            'guest_email' => 'first@example.test',
        ]);
        $authorized = Registration::query()->sole();
        $other = $this->createGuestRegistration('other@example.test');

        $this->get(route('public.tickets.pdf', $authorized))->assertOk();
        $this->get(route('public.tickets.pdf', $other))->assertNotFound();
    }

    public function test_authenticated_student_can_reopen_and_redownload_only_their_current_ticket(): void
    {
        $owner = $this->student('owner@student.mseuf.edu.ph');
        $other = $this->student('other@student.mseuf.edu.ph');
        $registration = $this->createStudentRegistration($owner);

        $this->actingAs($owner, 'web')
            ->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('PDF Delivery Event')
            ->assertSee(route('public.tickets.pdf', $registration), false);

        $this->get(route('public.tickets.show', $registration))->assertOk();
        $this->get(route('public.tickets.pdf', $registration))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($other, 'web')
            ->get(route('public.tickets.pdf', $registration))
            ->assertNotFound();
    }

    public function test_account_ticket_download_moves_to_the_new_owner_after_a_verified_transfer(): void
    {
        $sender = $this->student('sender@student.mseuf.edu.ph');
        $recipient = $this->student('recipient@student.mseuf.edu.ph');
        $this->event->update(['ticket_transfers_allowed' => true]);
        $registration = $this->createStudentRegistration($sender);
        $ticket = $registration->ticket;

        $transfer = $this->actingAs($sender, 'sanctum')
            ->postJson("/api/v1/tickets/{$ticket->id}/transfers", ['recipient' => $recipient->email])
            ->assertCreated()
            ->json('transfer.id');

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/v1/ticket-transfers/{$transfer}/accept")
            ->assertOk();

        $this->assertSame(2, $ticket->fresh()->credential_version);
        $this->actingAs($sender, 'web')->get(route('public.tickets.pdf', $registration))->assertNotFound();
        $this->actingAs($recipient, 'web')->get(route('public.tickets.pdf', $registration))->assertOk();
    }

    public function test_cancelled_or_revoked_tickets_cannot_be_downloaded(): void
    {
        $student = $this->student('current@student.mseuf.edu.ph');
        $registration = $this->createStudentRegistration($student);
        $ticket = $registration->ticket;

        $this->actingAs($student, 'web')->get(route('public.tickets.pdf', $registration))->assertOk();

        $ticket->update(['status' => Ticket::STATUS_REVOKED]);
        $this->get(route('public.tickets.pdf', $registration))->assertNotFound();

        $ticket->update(['status' => Ticket::STATUS_ACTIVE]);
        $registration->update(['status' => Registration::STATUS_CANCELLED]);
        $this->get(route('public.tickets.pdf', $registration))->assertNotFound();
    }

    public function test_ticket_without_a_valid_admission_credential_cannot_be_downloaded(): void
    {
        $student = $this->student('credential@student.mseuf.edu.ph');
        $registration = $this->createStudentRegistration($student);
        $registration->ticket->update(['encrypted_secret' => null]);

        $this->actingAs($student, 'web')
            ->get(route('public.tickets.pdf', $registration))
            ->assertNotFound();
    }

    private function createGuestRegistration(string $email): Registration
    {
        return app(RegisterAttendeeForEvent::class)->execute(
            eventId: $this->event->id,
            categoryId: $this->event->ticketCategories()->firstOrFail()->id,
            guestName: 'Guest Holder',
            guestEmail: $email,
        )['registration'];
    }

    private function createStudentRegistration(User $student): Registration
    {
        return app(RegisterForEvent::class)->execute($student, $this->event->id)['registration'];
    }

    private function student(string $email): User
    {
        return User::factory()->create([
            'name' => 'Verified Student',
            'email' => $email,
            'role' => User::ROLE_STUDENT,
            'email_verified_at' => now(),
            'firebase_uid' => 'firebase:'.strtolower($email),
        ]);
    }
}
