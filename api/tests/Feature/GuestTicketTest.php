<?php

namespace Tests\Feature;

use App\Actions\IssueGuestTicket;
use App\Actions\PrepareEventSnapshot;
use App\Actions\ValidateSnapshotPayload;
use App\Http\Controllers\Web\GuestTicketController;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GuestTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ingress.mode' => 'cloud']);
        $this->owner = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $venue = Venue::create(['name' => 'Hall', 'code' => 'HALL', 'default_capacity' => 10]);
        $this->event = Event::create([
            'organizer_id' => $this->owner->id, 'venue_id' => $venue->id, 'title' => 'Guest Event', 'slug' => 'guest-event',
            'capacity' => 3, 'registration_opens_at' => now()->subDay(), 'registration_closes_at' => now()->addDay(),
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2), 'status' => Event::STATUS_PUBLISHED,
        ]);
    }

    public function test_organizer_issues_accountless_pass_and_only_authorized_organizers_can_view_it(): void
    {
        $data = ['guest_name' => 'A <Guest>', 'guest_email' => 'Guest@Example.Test', 'attendee_type' => 'alumni'];
        $this->actingAs($this->owner)->post("/organizer/events/{$this->event->id}/guests", $data)->assertRedirect();
        $registration = Registration::where('event_id', $this->event->id)->firstOrFail();
        $this->assertNull($registration->user_id);
        $this->assertSame('guest@example.test', $registration->guest_email);
        $this->assertSame('alumni', $registration->attendee_type);
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.test']);
        $qr = $registration->ticket->getQrPayload();
        $this->assertNotEmpty($qr);
        $pass = "/organizer/events/{$this->event->id}/guests/{$registration->id}/pass";
        $this->get($pass)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Frame-Options', 'DENY')->assertSee('<svg', false)->assertSee('A &lt;Guest&gt;', false)
            ->assertDontSee($qr, false);
        $this->get("/organizer/events/{$this->event->id}/attendance")->assertOk()->assertSee('A &lt;Guest&gt;', false);
        $other = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        $this->actingAs($other)->get($pass)->assertForbidden();
        $this->actingAs($other)->post("/organizer/events/{$this->event->id}/guests", $data)->assertForbidden();
        $this->actingAs($this->owner)->post("/organizer/events/{$this->event->id}/cancel")->assertRedirect();
        $this->get($pass)->assertNotFound();
    }

    public function test_guest_pass_requires_https_outside_local_or_testing(): void
    {
        $this->actingAs($this->owner)->post("/organizer/events/{$this->event->id}/guests", [
            'guest_name' => 'Guest', 'guest_email' => 'guest@example.test', 'attendee_type' => 'guest',
        ])->assertRedirect();
        $registration = Registration::where('event_id', $this->event->id)->firstOrFail();
        $this->app->detectEnvironment(fn () => 'production');
        $controller = app(GuestTicketController::class);
        foreach ([
            fn () => $controller->show(Request::create('http://example.test/pass'), $this->event, $registration),
            fn () => $controller->store(Request::create('http://example.test/guests', 'POST'), $this->event, app(IssueGuestTicket::class)),
        ] as $request) {
            try {
                $request();
                $this->fail('An unencrypted guest operation was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_cloud_guest_pass_list_excludes_node_issued_walk_ins(): void
    {
        $this->actingAs($this->owner)->post("/organizer/events/{$this->event->id}/guests", [
            'guest_name' => 'Cloud Guest', 'guest_email' => 'cloud@example.test', 'attendee_type' => 'guest',
        ])->assertRedirect();
        Registration::create([
            'event_id' => $this->event->id, 'user_id' => null, 'attendee_type' => 'walk_in', 'walk_in_kind' => 'guest',
            'guest_name' => 'Node Walk In', 'guest_email' => 'node@example.test', 'status' => Registration::STATUS_CONFIRMED,
        ]);
        $this->get("/organizer/events/{$this->event->id}")->assertOk()
            ->assertSee('Cloud Guest')->assertDontSee('Node Walk In');
        $this->get("/organizer/events/{$this->event->id}/attendance")->assertOk()->assertSee('Node Walk In');
    }

    public function test_version_two_snapshot_rejects_invalid_guest_identity(): void
    {
        $this->actingAs($this->owner)->post("/organizer/events/{$this->event->id}/guests", [
            'guest_name' => 'Guest', 'guest_email' => 'guest@example.test', 'attendee_type' => 'guest',
        ])->assertRedirect();
        $this->event->update(['registration_closed_at' => now()]);
        $snapshot = app(PrepareEventSnapshot::class)->execute($this->event->id);
        $row = DB::table('event_snapshots')->where('id', $snapshot['id'])->first();
        $payload = json_decode(Crypt::decryptString($row->payload_ciphertext), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $payload['schema_version']);
        $this->assertNull($payload['registrations'][0]['attendee']['id']);
        foreach ([['id' => 42], ['kind' => 'admin'], ['student_id' => '123'], ['email' => 'Guest@example.test']] as $changes) {
            $bad = $payload;
            $bad['registrations'][0]['attendee'] = array_replace($bad['registrations'][0]['attendee'], $changes);
            try {
                app(ValidateSnapshotPayload::class)->execute(json_encode($bad, JSON_THROW_ON_ERROR));
                $this->fail('Invalid guest identity was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_postgresql_rejects_invalid_registration_identity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL enforces the identity check constraint.');
        }
        try {
            DB::transaction(fn () => DB::table('registrations')->insert([
                'event_id' => $this->event->id, 'user_id' => null, 'attendee_type' => 'student',
                'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('A student registration without a user was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('registrations', 0);
        }
    }

    public function test_duplicate_email_cutoff_and_capacity_apply_to_guest_and_student_registrations(): void
    {
        $this->event->update(['capacity' => 4]);
        $guest = ['guest_name' => 'Guest', 'guest_email' => 'guest@example.test', 'attendee_type' => 'guest'];
        $this->actingAs($this->owner)->post("/organizer/events/{$this->event->id}/guests", $guest)->assertRedirect();
        $this->post("/organizer/events/{$this->event->id}/guests", $guest)->assertSessionHasErrors('guest');
        $sameEmail = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'guest@example.test',
            'firebase_uid' => 'firebase-guest-email-student',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($sameEmail)->postJson("/api/v1/events/{$this->event->id}/registration")->assertCreated();
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'student@example.test',
            'firebase_uid' => 'firebase-guest-test-student',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($student)->postJson("/api/v1/events/{$this->event->id}/registration")->assertCreated();
        $this->actingAs($this->owner)->post("/organizer/events/{$this->event->id}/guests", [
            'guest_name' => 'Duplicate', 'guest_email' => 'STUDENT@example.test', 'attendee_type' => 'alumni',
        ])->assertRedirect();
        $this->post("/organizer/events/{$this->event->id}/guests", [
            'guest_name' => 'Extra', 'guest_email' => 'extra@example.test', 'attendee_type' => 'guest',
        ])->assertSessionHasErrors('guest');
        $this->assertDatabaseCount('registrations', 4);
        $this->event->update(['registration_closed_at' => now()]);
        $this->post("/organizer/events/{$this->event->id}/guests", [
            'guest_name' => 'Late', 'guest_email' => 'late@example.test', 'attendee_type' => 'guest',
        ])->assertSessionHasErrors('guest');
        $this->assertDatabaseCount('registrations', 4);
    }
}
