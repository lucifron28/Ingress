<?php

namespace Tests\Feature;

use App\Actions\RegisterForEvent;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class TicketPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $staff;

    private User $organizerA;

    private User $organizerB;

    private User $admin;

    private Event $eventA;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizerA = User::factory()->create([
            'name' => 'Organizer A',
            'role' => User::ROLE_ORGANIZER,
        ]);

        $this->organizerB = User::factory()->create([
            'name' => 'Organizer B',
            'role' => User::ROLE_ORGANIZER,
        ]);

        $this->staff = User::factory()->create([
            'name' => 'Staff Alex',
            'role' => User::ROLE_STAFF,
        ]);

        $this->admin = User::factory()->create([
            'name' => 'Admin User',
            'role' => User::ROLE_ADMIN,
        ]);

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

        $this->eventA = Event::create([
            'organizer_id' => $this->organizerA->id,
            'venue_id' => $venue->id,
            'title' => 'Event A',
            'slug' => 'event-a',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(2),
            'ends_at' => Carbon::now()->addDays(2)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        // Register student for event A
        $action = new RegisterForEvent;
        $res = $action->execute($this->student, $this->eventA->id);
        $this->ticket = $res['ticket'];
    }

    public function test_attendee_owner_can_view_ticket_with_qr_payload(): void
    {
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/tickets/{$this->ticket->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'ticket' => [
                    'id',
                    'public_id',
                    'status',
                    'issued_at',
                    'qr_payload', // Owner MUST receive qr_payload
                    'event',
                    'attendee',
                ],
            ]);

        $this->assertNotNull($response->json('ticket.qr_payload'));
        $this->assertStringStartsWith('ingress:v1:ING-', $response->json('ticket.qr_payload'));
    }

    public function test_cancelled_event_never_returns_ticket_qr(): void
    {
        $this->eventA->update(['status' => Event::STATUS_CANCELLED]);
        $detail = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/tickets/{$this->ticket->id}")->assertOk()
            ->assertJsonPath('ticket.event.status', 'cancelled');
        $this->assertArrayNotHasKey('qr_payload', $detail->json('ticket'));
        $list = $this->getJson('/api/v1/me/tickets')->assertOk()
            ->assertJsonPath('data.0.event.status', 'cancelled');
        $this->assertArrayNotHasKey('qr_payload', $list->json('data.0'));
    }

    public function test_ticket_never_returns_a_secret_that_does_not_match_its_verification_hash(): void
    {
        $this->assertNotNull($this->ticket->getQrPayload());
        $this->ticket->update(['encrypted_secret' => Crypt::encryptString(str_repeat('f', 64))]);
        $this->assertNull($this->ticket->fresh()->getQrPayload());
        $this->actingAs($this->student, 'sanctum')->getJson("/api/v1/tickets/{$this->ticket->id}")
            ->assertOk()->assertJsonPath('ticket.qr_payload', null);
    }

    public function test_staff_cannot_inspect_attendee_ticket(): void
    {
        $response = $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/tickets/{$this->ticket->id}");

        $response->assertStatus(403);
    }

    public function test_unrelated_organizer_cannot_inspect_ticket(): void
    {
        $response = $this->actingAs($this->organizerB, 'sanctum')
            ->getJson("/api/v1/tickets/{$this->ticket->id}");

        $response->assertStatus(403);
    }

    public function test_event_organizer_can_view_metadata_but_qr_payload_is_stripped(): void
    {
        $response = $this->actingAs($this->organizerA, 'sanctum')
            ->getJson("/api/v1/tickets/{$this->ticket->id}");

        $response->assertStatus(200)
            ->assertJson([
                'ticket' => [
                    'id' => $this->ticket->id,
                    'public_id' => $this->ticket->public_id,
                    'status' => 'active',
                    'attendee' => [
                        'name' => 'Ron Vincent Cada',
                        'student_id' => 'A23-35524',
                    ],
                ],
            ]);

        $this->assertArrayNotHasKey('qr_payload', $response->json('ticket'));
    }

    public function test_admin_can_view_metadata_but_qr_payload_is_stripped(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/tickets/{$this->ticket->id}");

        $response->assertStatus(200)
            ->assertJson([
                'ticket' => [
                    'id' => $this->ticket->id,
                    'public_id' => $this->ticket->public_id,
                    'status' => 'active',
                ],
            ]);

        $this->assertArrayNotHasKey('qr_payload', $response->json('ticket'));
    }

    public function test_owner_cannot_view_qr_payload_if_ticket_is_revoked(): void
    {
        $this->ticket->update([
            'status' => Ticket::STATUS_REVOKED,
            'revoked_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/tickets/{$this->ticket->id}");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('qr_payload', $response->json('ticket'));
    }
}
