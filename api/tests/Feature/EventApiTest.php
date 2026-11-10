<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    private Venue $venue;

    private User $organizer;

    private User $student;

    private Event $publishedEvent;

    private Event $draftEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizer = User::factory()->create([
            'role' => User::ROLE_ORGANIZER,
        ]);

        $this->student = User::factory()->create([
            'name' => 'Ron Vincent Cada',
            'email' => 'a23-35524@student.mseuf.edu.ph',
            'student_id' => 'A23-35524',
            'role' => User::ROLE_STUDENT,
        ]);

        $this->venue = Venue::create([
            'name' => 'MSEUF Gymnasium',
            'code' => 'GYM',
            'location' => 'Main Campus',
            'default_capacity' => 1000,
        ]);

        $this->publishedEvent = Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'Tech Summit 2026',
            'slug' => 'tech-summit-2026',
            'description' => 'Summit description here',
            'capacity' => 50,
            'registration_opens_at' => Carbon::now()->subDay(),
            'registration_closes_at' => Carbon::now()->addDays(5),
            'starts_at' => Carbon::now()->addDays(2),
            'ends_at' => Carbon::now()->addDays(2)->addHours(4),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $this->draftEvent = Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'Draft Secret Meeting',
            'slug' => 'draft-secret-meeting',
            'capacity' => 20,
            'registration_opens_at' => Carbon::now(),
            'registration_closes_at' => Carbon::now()->addDays(2),
            'starts_at' => Carbon::now()->addDays(3),
            'ends_at' => Carbon::now()->addDays(3)->addHours(2),
            'status' => Event::STATUS_DRAFT,
        ]);
    }

    public function test_unauthenticated_requests_to_events_return_401(): void
    {
        $this->getJson('/api/v1/events')->assertStatus(401);
        $this->getJson("/api/v1/events/{$this->publishedEvent->id}")->assertStatus(401);
    }

    public function test_api_returns_published_events_with_capacity_and_venue(): void
    {
        $response = $this->actingAs($this->student)
            ->getJson('/api/v1/events');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'slug',
                        'description',
                        'capacity',
                        'confirmed_registrations_count',
                        'remaining_capacity',
                        'is_registration_open',
                        'venue' => ['id', 'name', 'code', 'location'],
                    ],
                ],
            ]);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Tech Summit 2026'));
        $this->assertFalse($titles->contains('Draft Secret Meeting'));
    }

    public function test_api_returns_single_published_event(): void
    {
        $response = $this->actingAs($this->student)
            ->getJson("/api/v1/events/{$this->publishedEvent->id}");

        $response->assertStatus(200)
            ->assertJson([
                'event' => [
                    'id' => $this->publishedEvent->id,
                    'title' => 'Tech Summit 2026',
                    'capacity' => 50,
                    'remaining_capacity' => 50,
                    'is_registration_open' => true,
                ],
            ]);
    }

    public function test_api_returns_404_for_draft_event_to_unauthorized_user(): void
    {
        $response = $this->actingAs($this->student)
            ->getJson("/api/v1/events/{$this->draftEvent->id}");

        $response->assertStatus(404);
    }

    public function test_assigned_event_manager_can_read_their_draft_event_through_the_api(): void
    {
        config(['ingress.mode' => 'cloud']);

        $manager = User::factory()->create(['role' => User::ROLE_EVENT_MANAGER]);
        $this->draftEvent->managerAssignments()->create(['user_id' => $manager->id]);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/events/{$this->draftEvent->id}")
            ->assertOk()
            ->assertJsonPath('event.title', 'Draft Secret Meeting');
    }

    public function test_authenticated_student_events_index_returns_is_user_registered_true_after_registration(): void
    {
        // Before registration
        $responseBefore = $this->actingAs($this->student)->getJson('/api/v1/events');
        $responseBefore->assertStatus(200);
        $eventBefore = collect($responseBefore->json('data'))->firstWhere('id', $this->publishedEvent->id);
        $this->assertFalse($eventBefore['is_user_registered']);
        $this->assertNull($eventBefore['user_ticket']);

        // Register
        $this->actingAs($this->student)
            ->postJson("/api/v1/events/{$this->publishedEvent->id}/registration")
            ->assertStatus(201);

        // After registration
        $responseAfter = $this->actingAs($this->student)->getJson('/api/v1/events');
        $responseAfter->assertStatus(200);
        $eventAfter = collect($responseAfter->json('data'))->firstWhere('id', $this->publishedEvent->id);
        $this->assertTrue($eventAfter['is_user_registered']);
        $this->assertNotNull($eventAfter['user_ticket']);
        $this->assertEquals('active', $eventAfter['user_ticket']['status']);
    }

    public function test_authenticated_student_events_show_returns_ticket_summary(): void
    {
        // Register
        $regResponse = $this->actingAs($this->student)
            ->postJson("/api/v1/events/{$this->publishedEvent->id}/registration")
            ->assertStatus(201);
        $ticketPublicId = $regResponse->json('ticket.public_id');

        // Show event
        $response = $this->actingAs($this->student)->getJson("/api/v1/events/{$this->publishedEvent->id}");
        $response->assertStatus(200);
        $this->assertTrue($response->json('event.is_user_registered'));
        $this->assertNotNull($response->json('event.user_ticket'));
        $this->assertEquals($ticketPublicId, $response->json('event.user_ticket.public_id'));
        $this->assertEquals('active', $response->json('event.user_ticket.status'));
    }

    public function test_future_event_appears_in_events_discovery(): void
    {
        $response = $this->actingAs($this->student)->getJson('/api/v1/events');
        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Tech Summit 2026'));
    }

    public function test_event_currently_in_progress_appears_even_if_started_over_6_hours_ago(): void
    {
        $now = Carbon::now();
        Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'Symposium Started 8 Hours Ago',
            'slug' => 'symposium-started-8h-ago',
            'capacity' => 100,
            'registration_opens_at' => $now->copy()->subDays(2),
            'registration_closes_at' => $now->copy()->subHours(9),
            'starts_at' => $now->copy()->subHours(8),
            'ends_at' => $now->copy()->addHours(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($this->student)->getJson('/api/v1/events');
        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Symposium Started 8 Hours Ago'));
    }

    public function test_multi_day_ongoing_event_appears_in_events_discovery(): void
    {
        $now = Carbon::now();
        Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'Multi-Day University Festival',
            'slug' => 'multi-day-festival',
            'capacity' => 500,
            'registration_opens_at' => $now->copy()->subDays(5),
            'registration_closes_at' => $now->copy()->subDays(3),
            'starts_at' => $now->copy()->subDays(2),
            'ends_at' => $now->copy()->addDays(2),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($this->student)->getJson('/api/v1/events');
        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Multi-Day University Festival'));
    }

    public function test_ended_event_does_not_appear_in_events_discovery(): void
    {
        $now = Carbon::now();
        Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'Yesterday Concluded Event',
            'slug' => 'yesterday-concluded-event',
            'capacity' => 100,
            'registration_opens_at' => $now->copy()->subDays(3),
            'registration_closes_at' => $now->copy()->subDays(2),
            'starts_at' => $now->copy()->subDay()->subHours(3),
            'ends_at' => $now->copy()->subDay(),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($this->student)->getJson('/api/v1/events');
        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertFalse($titles->contains('Yesterday Concluded Event'));
    }

    public function test_draft_and_cancelled_events_remain_filtered_out(): void
    {
        $now = Carbon::now();
        Event::create([
            'organizer_id' => $this->organizer->id,
            'venue_id' => $this->venue->id,
            'title' => 'Future Cancelled Event',
            'slug' => 'future-cancelled-event',
            'capacity' => 50,
            'registration_opens_at' => $now->copy()->subDay(),
            'registration_closes_at' => $now->copy()->addDay(),
            'starts_at' => $now->copy()->addDay(),
            'ends_at' => $now->copy()->addDays(2),
            'status' => Event::STATUS_CANCELLED,
        ]);

        $response = $this->actingAs($this->student)->getJson('/api/v1/events');
        $response->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertFalse($titles->contains('Draft Secret Meeting'));
        $this->assertFalse($titles->contains('Future Cancelled Event'));
    }
}
