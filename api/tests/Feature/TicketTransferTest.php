<?php

namespace Tests\Feature;

use App\Actions\PrepareEventSnapshot;
use App\Actions\RegisterForEvent;
use App\Models\AccessPoint;
use App\Models\Admission;
use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $organizer;

    private User $sender;

    private User $recipient;

    private Event $event;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->sender = User::factory()->create([
            'name' => 'Sender Student',
            'email' => 'sender@student.mseuf.edu.ph',
            'student_id' => 'S-1001',
            'role' => User::ROLE_STUDENT,
            'firebase_uid' => 'firebase-sender-student',
            'email_verified_at' => now(),
        ]);
        $this->recipient = User::factory()->create([
            'name' => 'Recipient Student',
            'email' => 'recipient@student.mseuf.edu.ph',
            'student_id' => 'S-1002',
            'role' => User::ROLE_STUDENT,
            'firebase_uid' => 'firebase-recipient-student',
            'email_verified_at' => now(),
        ]);

        $venue = Venue::create([
            'name' => 'Transfer Venue',
            'code' => 'TRANSFER',
            'location' => 'Main Campus',
            'default_capacity' => 100,
        ]);
        $this->event = Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $venue->id,
            'title' => 'Transfer Test Event',
            'slug' => 'transfer-test-event',
            'capacity' => 10,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
            'ticket_transfers_allowed' => true,
        ]);
        $this->ticket = (new RegisterForEvent)->execute($this->sender, $this->event->id)['ticket'];
    }

    public function test_recipient_acceptance_changes_owner_and_invalidates_the_old_qr(): void
    {
        $oldQr = $this->ticket->getQrPayload();

        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => strtoupper($this->recipient->email),
        ])->assertCreated()->assertJsonPath('transfer.status', 'pending');

        $this->actingAs($this->sender, 'sanctum')->getJson("/api/v1/tickets/{$this->ticket->id}")
            ->assertOk()
            ->assertJsonPath('ticket.qr_payload', $oldQr)
            ->assertJsonPath('ticket.event.ticket_transfers_allowed', true);
        $inbox = $this->actingAs($this->recipient, 'sanctum')->getJson('/api/v1/me/ticket-transfers')
            ->assertOk()->assertJsonPath('data.0.direction', 'incoming');
        $transferId = $inbox->json('data.0.id');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]), 'sanctum')
            ->postJson("/api/v1/ticket-transfers/{$transferId}/accept")->assertForbidden();
        $this->actingAs($this->recipient, 'sanctum')
            ->postJson("/api/v1/ticket-transfers/{$transferId}/accept")->assertOk();

        $registration = $this->ticket->fresh()->registration;
        $rotatedTicket = $this->ticket->fresh();
        $newQr = $rotatedTicket->getQrPayload();
        $this->assertSame($this->recipient->id, $registration->user_id);
        $this->assertSame(2, $rotatedTicket->credential_version);
        $this->assertNotSame($oldQr, $newQr);
        $this->assertFalse($rotatedTicket->verifySecret(explode(':', $oldQr)[3]));
        $this->assertDatabaseHas('ticket_transfers', [
            'id' => $transferId,
            'status' => TicketTransfer::STATUS_ACCEPTED,
            'pending_ticket_id' => null,
        ]);
        $this->actingAs($this->sender, 'sanctum')->getJson('/api/v1/me/tickets')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->recipient, 'sanctum')->getJson('/api/v1/me/tickets')
            ->assertOk()->assertJsonPath('data.0.qr_payload', $newQr);
    }

    public function test_decline_and_sender_cancellation_leave_the_ticket_with_the_sender(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertCreated();
        $this->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertUnprocessable();
        $transferId = $this->actingAs($this->recipient, 'sanctum')->getJson('/api/v1/me/ticket-transfers')->json('data.0.id');
        $this->postJson("/api/v1/ticket-transfers/{$transferId}/decline")->assertOk();
        $this->assertDatabaseHas('ticket_transfers', ['id' => $transferId, 'status' => TicketTransfer::STATUS_DECLINED, 'pending_ticket_id' => null]);

        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertCreated();
        $transferId = $this->actingAs($this->sender, 'sanctum')->getJson('/api/v1/me/ticket-transfers')->json('data.0.id');
        $this->deleteJson("/api/v1/ticket-transfers/{$transferId}")->assertOk();
        $this->assertDatabaseHas('registrations', ['id' => $this->ticket->registration_id, 'user_id' => $this->sender->id]);
        $this->assertDatabaseHas('ticket_transfers', ['id' => $transferId, 'status' => TicketTransfer::STATUS_CANCELLED, 'pending_ticket_id' => null]);
    }

    public function test_disabled_event_rejects_a_transfer_request(): void
    {
        $this->event->update(['ticket_transfers_allowed' => false]);

        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('ticket_transfers', 0);
    }

    public function test_returns_429_after_ten_transfer_requests_per_minute(): void
    {
        $this->actingAs($this->sender, 'sanctum');

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $response = $this->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
                'recipient' => $this->recipient->email,
            ]);

            $attempt === 1 ? $response->assertCreated() : $response->assertUnprocessable();
        }

        $this->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertTooManyRequests();

        $this->assertDatabaseCount('ticket_transfers', 1);
    }

    public function test_disabling_event_transfers_expires_pending_requests(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertCreated();
        $transferId = TicketTransfer::firstOrFail()->id;

        $this->actingAs($this->organizer)->put("/organizer/events/{$this->event->id}", [
            'venue_id' => $this->event->venue_id,
            'title' => $this->event->title,
            'description' => $this->event->description,
            'capacity' => $this->event->capacity,
            'registration_opens_at' => $this->event->registration_opens_at->format('Y-m-d H:i:s'),
            'registration_closes_at' => $this->event->registration_closes_at->format('Y-m-d H:i:s'),
            'starts_at' => $this->event->starts_at->format('Y-m-d H:i:s'),
            'ends_at' => $this->event->ends_at->format('Y-m-d H:i:s'),
            're_entry_allowed' => 0,
            'ticket_transfers_allowed' => 0,
        ])->assertRedirect();

        $this->assertDatabaseHas('ticket_transfers', [
            'id' => $transferId,
            'status' => TicketTransfer::STATUS_EXPIRED,
            'pending_ticket_id' => null,
        ]);
    }

    public function test_cancelling_event_expires_pending_requests(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertCreated();
        $transferId = TicketTransfer::firstOrFail()->id;

        $this->actingAs($this->organizer)->post("/organizer/events/{$this->event->id}/cancel")->assertRedirect();

        $this->assertDatabaseHas('ticket_transfers', [
            'id' => $transferId,
            'status' => TicketTransfer::STATUS_EXPIRED,
            'pending_ticket_id' => null,
        ]);
    }

    public function test_transfer_requires_a_different_verified_student_who_is_not_already_registered(): void
    {
        $unverified = User::factory()->unverified()->create(['role' => User::ROLE_STUDENT]);
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $unverified->email,
        ])->assertUnprocessable();
        $this->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->sender->email,
        ])->assertUnprocessable();

        (new RegisterForEvent)->execute($this->recipient, $this->event->id);
        $this->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('ticket_transfers', 0);
    }

    public function test_transfer_requires_a_verified_student_sender_and_rejects_admitted_tickets(): void
    {
        $this->sender->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => strtolower($this->recipient->student_id),
        ])->assertUnprocessable();
        $this->sender->forceFill(['email_verified_at' => now()])->save();
        $this->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => strtolower($this->recipient->student_id),
        ])->assertCreated();
        $transferId = TicketTransfer::firstOrFail()->id;
        $this->deleteJson("/api/v1/ticket-transfers/{$transferId}")->assertOk();

        $gate = AccessPoint::create([
            'event_id' => $this->event->id,
            'name' => 'Main Gate',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        Admission::create([
            'ticket_id' => $this->ticket->id,
            'access_point_id' => $gate->id,
            'scanned_by_user_id' => $staff->id,
            'client_scan_id' => 'transfer-test-scan-0001',
            'credential_version' => 1,
            'direction' => Admission::DIRECTION_ENTRY,
            'admitted_at' => now(),
        ]);
        $this->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('ticket_transfers', [
            'id' => $transferId,
            'status' => TicketTransfer::STATUS_CANCELLED,
            'pending_ticket_id' => null,
        ]);
    }

    public function test_guest_contact_email_does_not_block_verified_student_transfer(): void
    {
        $guestRegistration = Registration::create([
            'event_id' => $this->event->id,
            'user_id' => null,
            'attendee_type' => 'guest',
            'guest_name' => 'Guest Recipient',
            'guest_email' => $this->recipient->email,
            'status' => Registration::STATUS_CONFIRMED,
        ]);

        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertCreated();
        $transferId = TicketTransfer::firstOrFail()->id;

        $this->actingAs($this->recipient, 'sanctum')
            ->postJson("/api/v1/ticket-transfers/{$transferId}/accept")
            ->assertOk();

        $this->assertSame($this->recipient->id, $this->ticket->registration()->firstOrFail()->fresh()->user_id);
        $this->assertDatabaseHas('registrations', [
            'id' => $guestRegistration->id,
            'user_id' => null,
            'guest_email' => $this->recipient->email,
        ]);
    }

    public function test_registration_cutoff_does_not_close_transfers_but_snapshot_preparation_does(): void
    {
        $this->event->update(['registration_closed_at' => now(), 'registration_closes_at' => now()->subSecond()]);
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertCreated();
        $transferId = TicketTransfer::firstOrFail()->id;

        config(['ingress.mode' => 'cloud']);
        app(PrepareEventSnapshot::class)->execute($this->event->id);

        $this->assertDatabaseHas('ticket_transfers', [
            'id' => $transferId,
            'status' => TicketTransfer::STATUS_EXPIRED,
            'pending_ticket_id' => null,
        ]);
        config(['ingress.mode' => 'legacy']);
        $this->actingAs($this->recipient, 'sanctum')
            ->postJson("/api/v1/ticket-transfers/{$transferId}/accept")->assertUnprocessable();
        $this->assertDatabaseHas('registrations', ['id' => $this->ticket->registration_id, 'user_id' => $this->sender->id]);
    }

    public function test_transfer_request_and_accept_are_rejected_at_or_after_event_end(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertCreated();
        $transferId = TicketTransfer::firstOrFail()->id;
        $eventEnd = $this->event->ends_at->copy();

        Carbon::setTestNow($eventEnd);
        try {
            $this->actingAs($this->sender, 'sanctum')->getJson("/api/v1/tickets/{$this->ticket->id}")
                ->assertOk()->assertJsonPath('ticket.event.ticket_transfers_allowed', false);
            $this->actingAs($this->recipient, 'sanctum')
                ->postJson("/api/v1/ticket-transfers/{$transferId}/accept")->assertUnprocessable();
            $this->assertDatabaseHas('ticket_transfers', ['id' => $transferId, 'status' => TicketTransfer::STATUS_PENDING]);
            $this->assertDatabaseHas('registrations', ['id' => $this->ticket->registration_id, 'user_id' => $this->sender->id]);

            $this->actingAs($this->sender, 'sanctum')->deleteJson("/api/v1/ticket-transfers/{$transferId}")->assertOk();
            Carbon::setTestNow($eventEnd->copy()->addSecond());
            $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
                'recipient' => $this->recipient->email,
            ])->assertUnprocessable();
            $this->assertDatabaseHas('ticket_transfers', ['id' => $transferId, 'status' => TicketTransfer::STATUS_CANCELLED]);
            $this->assertDatabaseCount('ticket_transfers', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_organizer_event_page_shows_transfer_audit_history(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/transfers", [
            'recipient' => $this->recipient->email,
        ])->assertCreated();

        $this->actingAs($this->organizer)->get("/organizer/events/{$this->event->id}")
            ->assertOk()
            ->assertSee('Ticket transfer history')
            ->assertSee('Sender Student')
            ->assertSee('Recipient Student')
            ->assertSee($this->ticket->public_id);
    }
}
