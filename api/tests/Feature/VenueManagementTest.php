<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VenueManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_create_and_update_venues_for_event_setup(): void
    {
        config(['ingress.mode' => 'cloud']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/organizer/venues')
            ->assertOk()
            ->assertSee('Venue management');

        $this->actingAs($admin)
            ->post('/organizer/venues', [
                'name' => 'Innovation Hall',
                'code' => 'innovation-hall',
                'location' => 'North Campus',
                'default_capacity' => 280,
            ])
            ->assertRedirect('/organizer/venues')
            ->assertSessionHas('success');

        $venue = Venue::query()->where('code', 'INNOVATION-HALL')->firstOrFail();

        $this->actingAs($admin)
            ->put("/organizer/venues/{$venue->id}", [
                'name' => 'Updated Innovation Hall',
                'code' => 'INNOVATION-HALL',
                'location' => 'South Campus',
                'default_capacity' => 320,
            ])
            ->assertRedirect('/organizer/venues')
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->get(route('organizer.events.create'))
            ->assertOk()
            ->assertSee('Updated Innovation Hall')
            ->assertSee('320');
    }

    public function test_non_admin_organizer_cannot_view_or_change_venues(): void
    {
        config(['ingress.mode' => 'cloud']);
        $organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create([
            'name' => 'Hall',
            'code' => 'HALL',
            'location' => 'Campus',
            'default_capacity' => 100,
        ]);

        $this->actingAs($organizer)->get('/organizer/venues')->assertForbidden();
        $this->actingAs($organizer)->post('/organizer/venues', [
            'name' => 'Unauthorized Hall',
            'code' => 'NOPE',
            'default_capacity' => 100,
        ])->assertForbidden();
        $this->actingAs($organizer)->put("/organizer/venues/{$venue->id}", [
            'name' => 'Changed Hall',
            'code' => 'HALL',
            'default_capacity' => 100,
        ])->assertForbidden();

        $this->assertDatabaseHas('venues', ['id' => $venue->id, 'name' => 'Hall']);
        $this->assertDatabaseMissing('venues', ['code' => 'NOPE']);
    }

    public function test_venue_code_is_normalized_and_duplicate_codes_are_rejected(): void
    {
        config(['ingress.mode' => 'cloud']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Venue::create([
            'name' => 'Main Hall',
            'code' => 'MAIN-HALL',
            'default_capacity' => 200,
        ]);

        $this->actingAs($admin)->post('/organizer/venues', [
            'name' => 'Second Main Hall',
            'code' => 'main-hall',
            'default_capacity' => 100,
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseCount('venues', 1);
    }

    public function test_venue_details_cannot_change_after_a_referencing_event_snapshot_is_prepared(): void
    {
        config(['ingress.mode' => 'cloud']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create([
            'name' => 'Auditorium',
            'code' => 'AUD',
            'location' => 'Campus',
            'default_capacity' => 500,
        ]);
        $event = Event::forceCreate([
            'organizer_id' => $owner->id,
            'venue_id' => $venue->id,
            'title' => 'Finalized Event',
            'slug' => 'finalized-event',
            'capacity' => 400,
            'registration_opens_at' => Carbon::now()->subDays(3),
            'registration_closes_at' => Carbon::now()->subDay(),
            'starts_at' => Carbon::now()->addDay(),
            'ends_at' => Carbon::now()->addDay()->addHours(2),
            'snapshot_prepared_at' => Carbon::now(),
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $this->actingAs($admin)->put("/organizer/venues/{$venue->id}", [
            'name' => 'Renamed Auditorium',
            'code' => 'AUD-NEW',
            'location' => 'Updated Campus',
            'default_capacity' => 600,
        ])->assertSessionHasErrors('venue');

        $this->assertDatabaseHas('venues', [
            'id' => $venue->id,
            'name' => 'Auditorium',
            'code' => 'AUD',
            'location' => 'Campus',
        ]);
        $this->assertDatabaseHas('events', ['id' => $event->id, 'snapshot_prepared_at' => $event->snapshot_prepared_at]);
    }
}
