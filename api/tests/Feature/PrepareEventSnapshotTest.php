<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrepareEventSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedStudent(array $attributes = []): User
    {
        $id = (string) Str::uuid();

        return User::factory()->create(array_merge([
            'role' => User::ROLE_STUDENT,
            'email' => "snapshot-{$id}@student.mseuf.edu.ph",
            'firebase_uid' => "firebase:{$id}",
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function createEvent(): Event
    {
        config(['ingress.mode' => 'cloud']);
        $owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'location' => 'Campus', 'default_capacity' => 20]);

        return Event::create([
            'organizer_id' => $owner->id, 'venue_id' => $venue->id, 'title' => 'Event', 'slug' => 'event',
            'capacity' => 20, 'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(), 'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(3), 'status' => Event::STATUS_PUBLISHED,
        ]);
    }

    public function test_preparation_requires_owner_and_closed_registration(): void
    {
        $event = $this->createEvent();
        $other = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->actingAs($other)->post("/organizer/events/{$event->id}/prepare-snapshot")->assertForbidden();
        $this->post("/organizer/events/{$event->id}/discard-snapshot")->assertForbidden();
        $owner = User::findOrFail($event->organizer_id);
        $this->actingAs($owner)->from("/organizer/events/{$event->id}")->followingRedirects()->post("/organizer/events/{$event->id}/prepare-snapshot")
            ->assertOk()->assertSee('Close registration before preparing a snapshot.');
        $this->assertDatabaseCount('event_snapshots', 0);
        $this->assertNull($event->fresh()->snapshot_prepared_at);
    }

    public function test_existing_admissions_prevent_preparation(): void
    {
        $event = $this->createEvent();
        $owner = User::findOrFail($event->organizer_id);
        $student = $this->verifiedStudent();
        $gate = AccessPoint::create(['event_id' => $event->id, 'name' => 'Gate', 'code' => 'G1', 'is_active' => true]);
        $ticketId = $this->actingAs($student)->postJson("/api/v1/events/{$event->id}/registration")->assertCreated()->json('ticket.id');
        DB::table('admissions')->insert([
            'ticket_id' => $ticketId, 'access_point_id' => $gate->id, 'scanned_by_user_id' => $owner->id,
            'client_scan_id' => 'historical-scan', 'credential_version' => 1, 'admitted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration");
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertSessionHasErrors('event');
        $this->assertDatabaseCount('event_snapshots', 0);
        $this->assertNull($event->fresh()->snapshot_prepared_at);
    }

    public function test_legacy_runtime_cannot_prepare_a_snapshot(): void
    {
        $event = $this->createEvent();
        config(['ingress.mode' => 'legacy']);
        $owner = User::findOrFail($event->organizer_id);
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/prepare-snapshot")->assertStatus(503);
        $this->assertDatabaseCount('event_snapshots', 0);
    }

    public function test_snapshot_contains_encrypted_roster_and_hashes_without_secrets_and_is_idempotent(): void
    {
        $event = $this->createEvent();
        $owner = User::findOrFail($event->organizer_id);
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        $student = $this->verifiedStudent(['name' => 'Snapshot Attendee']);
        $gate = AccessPoint::create(['event_id' => $event->id, 'name' => 'Gate', 'code' => 'G1', 'is_active' => true]);
        EventStaff::create(['event_id' => $event->id, 'user_id' => $staff->id, 'access_point_id' => $gate->id]);
        $registration = $this->actingAs($student)->postJson("/api/v1/events/{$event->id}/registration")->assertCreated();
        $rawQr = $registration->json('qr_payload');
        $ticketId = $registration->json('ticket.id');
        $registrationId = $registration->json('registration_id');
        $categoryId = DB::table('registrations')->where('id', $registrationId)->value('ticket_category_id');
        $this->assertNotNull($categoryId);
        $ticketHash = DB::table('tickets')->where('id', $ticketId)->value('qr_hash');
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration")->assertRedirect();
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        $snapshot = DB::table('event_snapshots')->where('event_id', $event->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(1, $snapshot->version);
        $this->assertStringNotContainsString('Snapshot Attendee', $snapshot->payload_ciphertext);
        $this->assertStringNotContainsString($staff->getAuthPassword(), $snapshot->payload_ciphertext);
        $json = Crypt::decryptString($snapshot->payload_ciphertext);
        $this->assertSame($snapshot->sha256, hash('sha256', $json));
        $this->assertStringNotContainsString($rawQr, $json);
        $this->assertStringNotContainsString(explode(':', $rawQr)[3], $json);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($snapshot->id, $payload['snapshot_id']);
        $this->assertArrayNotHasKey('registration_eligibility', $payload['event']);
        $this->assertArrayNotHasKey('ticket_category_id', $payload['registrations'][0]);
        $this->assertSame($ticketHash, $payload['registrations'][0]['ticket']['qr_hash']);
        $this->assertSame('Snapshot Attendee', $payload['registrations'][0]['attendee']['name']);
        $this->assertSame($staff->name, $payload['staff'][0]['user']['name']);
        $this->assertStringNotContainsString($staff->getAuthPassword(), $json);
        $this->assertArrayNotHasKey('password_hash', $payload['staff'][0]['user']);
        $this->assertSame($gate->id, $payload['staff'][0]['access_point_id']);
        $this->assertNotNull($event->fresh()->snapshot_prepared_at);
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        $this->assertDatabaseCount('event_snapshots', 1);
        $this->assertSame(1, $event->fresh()->snapshot_version);
        $page = $this->get("/organizer/events/{$event->id}")->assertOk();
        $page->assertSee('Node admission requires an activated authority grant')->assertDontSee($staff->getAuthPassword());
    }

    public function test_prepared_event_rejects_changes_and_discard_creates_new_version(): void
    {
        $event = $this->createEvent();
        $owner = User::findOrFail($event->organizer_id);
        $staff = User::factory()->create(['role' => User::ROLE_STAFF]);
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration");
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        $this->post("/organizer/events/{$event->id}/access-points", ['name' => 'Gate', 'code' => 'G1'])
            ->assertSessionHasErrors('event');
        $this->post("/organizer/events/{$event->id}/staff", ['user_id' => $staff->id])
            ->assertSessionHasErrors('event');
        $this->post("/organizer/events/{$event->id}/publish")->assertSessionHasErrors('event');
        $this->put("/organizer/events/{$event->id}", [
            'venue_id' => $event->venue_id, 'title' => 'Changed', 'capacity' => 20,
            'registration_opens_at' => now()->subDay()->toDateTimeString(),
            'registration_closes_at' => now()->subSecond()->toDateTimeString(),
            'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertSessionHasErrors('event');
        $this->assertSame('Event', $event->fresh()->title);
        $this->assertDatabaseCount('access_points', 0);
        $this->assertDatabaseCount('event_staff', 0);
        $this->post("/organizer/events/{$event->id}/discard-snapshot")->assertRedirect();
        $this->assertNull($event->fresh()->snapshot_prepared_at);
        $this->assertNotNull(DB::table('event_snapshots')->where('event_id', $event->id)->where('version', 1)->value('invalidated_at'));
        $this->post("/organizer/events/{$event->id}/access-points", ['name' => 'Gate', 'code' => 'G1'])->assertSessionHas('success');
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        $this->assertSame(2, $event->fresh()->snapshot_version);
        $this->assertDatabaseCount('event_snapshots', 2);
    }

    public function test_future_registration_window_is_not_treated_as_closed(): void
    {
        $event = $this->createEvent();
        $event->update(['registration_opens_at' => now()->addHour()]);
        $owner = User::findOrFail($event->organizer_id);
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/prepare-snapshot")
            ->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('event_snapshots', 0);
    }

    public function test_ended_event_cannot_be_prepared(): void
    {
        $event = $this->createEvent();
        $event->update(['registration_closed_at' => now()->subHour(), 'ends_at' => now()->subSecond()]);
        $owner = User::findOrFail($event->organizer_id);
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/prepare-snapshot")
            ->assertSessionHasErrors('event');
        $this->assertDatabaseCount('event_snapshots', 0);
    }

    public function test_prepared_snapshot_keeps_registration_closed_if_clock_moves_back(): void
    {
        $this->freezeTime();
        $event = $this->createEvent();
        $event->update(['registration_opens_at' => now()->subDays(3), 'registration_closes_at' => now()->subDay()]);
        $owner = User::findOrFail($event->organizer_id);
        $student = $this->verifiedStudent();
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        $this->travel(-2)->days();
        $this->actingAs($student)->getJson("/api/v1/events/{$event->id}")
            ->assertOk()->assertJsonPath('event.is_registration_open', false);
        $this->postJson("/api/v1/events/{$event->id}/registration")->assertUnprocessable();
        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_corrupt_prepared_snapshot_is_not_reused(): void
    {
        $event = $this->createEvent();
        $owner = User::findOrFail($event->organizer_id);
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration");
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        DB::table('event_snapshots')->where('event_id', $event->id)->update(['sha256' => str_repeat('0', 64)]);
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertStatus(500);
        $this->assertNotNull($event->fresh()->snapshot_prepared_at);
        $this->assertDatabaseCount('event_snapshots', 1);
    }

    public function test_snapshot_from_another_event_cannot_be_substituted(): void
    {
        $event = $this->createEvent();
        $otherEvent = $event->replicate();
        $otherEvent->slug = 'other-event';
        $otherEvent->save();
        $owner = User::findOrFail($event->organizer_id);
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration");
        $this->post("/organizer/events/{$otherEvent->id}/close-registration");
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        $this->post("/organizer/events/{$otherEvent->id}/prepare-snapshot")->assertRedirect();
        $other = DB::table('event_snapshots')->where('event_id', $otherEvent->id)->first();
        DB::table('event_snapshots')->where('event_id', $event->id)->update([
            'payload_ciphertext' => $other->payload_ciphertext, 'sha256' => $other->sha256,
        ]);
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertStatus(500);
        $this->assertNotNull($event->fresh()->snapshot_prepared_at);
    }

    public function test_event_update_cannot_set_snapshot_metadata(): void
    {
        $event = $this->createEvent();
        $owner = User::findOrFail($event->organizer_id);
        $this->actingAs($owner)->put("/organizer/events/{$event->id}", [
            'venue_id' => $event->venue_id, 'title' => 'Changed', 'capacity' => 20,
            'registration_opens_at' => now()->subDay()->toDateTimeString(),
            'registration_closes_at' => now()->subSecond()->toDateTimeString(),
            'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addDays(2)->toDateTimeString(),
            'snapshot_version' => 99, 'snapshot_prepared_at' => now()->toDateTimeString(),
        ])->assertRedirect();
        $this->assertSame(0, $event->fresh()->snapshot_version);
        $this->assertNull($event->fresh()->snapshot_prepared_at);
        $this->assertDatabaseCount('event_snapshots', 0);
    }

    public function test_cancellation_invalidates_prepared_snapshot(): void
    {
        $event = $this->createEvent();
        $owner = User::findOrFail($event->organizer_id);
        $this->actingAs($owner)->post("/organizer/events/{$event->id}/close-registration");
        $this->post("/organizer/events/{$event->id}/prepare-snapshot")->assertRedirect();
        $this->post("/organizer/events/{$event->id}/cancel")->assertRedirect();
        $this->assertSame(Event::STATUS_CANCELLED, $event->fresh()->status);
        $this->assertNull($event->fresh()->snapshot_prepared_at);
        $this->assertNotNull(DB::table('event_snapshots')->where('event_id', $event->id)->value('invalidated_at'));
    }
}
