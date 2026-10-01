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
    private Event $publishedEvent;
    private Event $draftEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizer = User::factory()->create([
            'role' => User::ROLE_ORGANIZER,
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

    public function test_api_returns_published_events_with_capacity_and_venue(): void
    {
        $response = $this->getJson('/api/v1/events');

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
        $response = $this->getJson("/api/v1/events/{$this->publishedEvent->id}");

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
        $response = $this->getJson("/api/v1/events/{$this->draftEvent->id}");

        $response->assertStatus(404);
    }
}
