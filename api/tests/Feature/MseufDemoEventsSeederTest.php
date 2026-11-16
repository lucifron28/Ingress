<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Database\Seeders\MseufDemoEventsSeeder;
use Database\Seeders\VenueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class MseufDemoEventsSeederTest extends TestCase
{
    use RefreshDatabase;

    private User $organizer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'testing',
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'ingress.mode' => 'cloud',
            'ingress.render_environment' => false,
            'ingress.render_service_id' => null,
            'ingress.mseuf_demo_events.enabled' => true,
            'ingress.mseuf_demo_events.organizer_id' => null,
            'database.connections.sqlite.host' => null,
        ]);

        $this->organizer = User::factory()->create(['role' => User::ROLE_ORGANIZER]);
        config(['ingress.mseuf_demo_events.organizer_id' => $this->organizer->id]);

        $this->seed(VenueSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_opt_in_seeds_three_exact_draft_events_with_pht_schedules_categories_and_gates(): void
    {
        $venueCount = Venue::count();
        $this->seed(MseufDemoEventsSeeder::class);

        $intramurals = Event::where('slug', 'mseuf-university-intramurals-2026-demo')->sole();
        $yearEnd = Event::where('slug', 'mseuf-year-end-party-2026-demo')->sole();
        $pageant = Event::where('slug', 'miss-enverga-university-2026-demo')->sole();

        $this->assertSame(3, Event::count());
        $this->assertSame($venueCount, Venue::count(), 'The fixture must reuse venue records without creating new ones.');
        $this->assertSame(1, User::count(), 'The fixture must reuse the supplied organizer and create no identities.');
        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());

        $this->assertEvent($intramurals, 'MSEUF University Intramurals 2026 — Demo', 'GYM', 500, 'student', false, [
            'registration_opens_at' => '2026-11-15 00:00:00',
            'registration_closes_at' => '2026-11-16 00:00:00',
            'starts_at' => '2026-11-16 01:00:00',
            'ends_at' => '2026-11-16 14:00:00',
        ]);
        $this->assertSame(
            'Celebrate Envergan unity and sportsmanship through athletic competitions, spirited college rivalries, team performances, and activities that bring the university community together. Students are invited to support their respective colleges and celebrate teamwork, determination, and school pride.',
            $intramurals->description,
        );
        $this->assertCategory($intramurals, 'General Admission', 'student', 500);
        $this->assertGates($intramurals, ['GATE-A' => 'Gate A', 'GATE-B' => 'Gate B', 'MAIN' => 'Main Entrance']);

        $this->assertEvent($yearEnd, 'MSEUF Year-End Party 2026 — Demo', 'MPH', 200, 'student', true, [
            'registration_opens_at' => '2026-11-16 00:00:00',
            'registration_closes_at' => '2026-11-20 08:00:00',
            'starts_at' => '2026-11-20 09:00:00',
            'ends_at' => '2026-11-20 14:00:00',
        ]);
        $this->assertSame(
            'Celebrate the year\'s achievements, friendships, and memorable experiences at the MSEUF Year-End Party. Join fellow Envergans for an evening of music, performances, interactive activities, and university spirit. This is a university-wide simulation, not a CCMS Year-End Party.',
            $yearEnd->description,
        );
        $this->assertStringContainsString('— Demo', $intramurals->title);
        $this->assertStringContainsString('— Demo', $yearEnd->title);
        $this->assertStringContainsString('— Demo', $pageant->title);
        $this->assertCategory($yearEnd, 'General Admission', 'student', 200);
        $this->assertGates($yearEnd, ['MAIN' => 'Main Entrance']);

        $this->assertEvent($pageant, 'Miss Enverga University 2026 — Demo', 'GYM', 400, 'public', false, [
            'registration_opens_at' => '2026-11-16 00:00:00',
            'registration_closes_at' => '2026-11-18 09:00:00',
            'starts_at' => '2026-11-18 10:00:00',
            'ends_at' => '2026-11-18 14:00:00',
        ]);
        $this->assertSame(
            'Witness elegance, confidence, intelligence, and Envergan excellence at Miss Enverga University 2026. Celebrate outstanding representatives as they showcase their talents, advocacies, and dedication to the Envergan community. Students, alumni, families, and outside supporters are welcome.',
            $pageant->description,
        );
        $this->assertCategory($pageant, 'General Admission', 'public', 300);
        $this->assertCategory($pageant, 'Supporter / Alumni Pass', 'public', 100);
        $this->assertSame(400, $pageant->ticketCategories()->sum('capacity'));
        $this->assertGates($pageant, ['GATE-A' => 'Gate A', 'MAIN' => 'Main Entrance']);
    }

    public function test_rerunning_the_fixture_does_not_duplicate_events_categories_or_access_points(): void
    {
        $this->seed(MseufDemoEventsSeeder::class);
        $this->seed(MseufDemoEventsSeeder::class);

        $this->assertSame(3, Event::count());
        $this->assertSame(4, TicketCategory::count());
        $this->assertSame(6, DB::table('access_points')->count());
    }

    public function test_fixture_refuses_to_modify_an_event_with_registration_history_before_any_fixture_changes(): void
    {
        $this->seed(MseufDemoEventsSeeder::class);

        $intramurals = Event::where('slug', 'mseuf-university-intramurals-2026-demo')->sole();
        $yearEnd = Event::where('slug', 'mseuf-year-end-party-2026-demo')->sole();
        $yearEnd->update(['description' => 'Keep this existing local draft.']);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        Registration::create([
            'event_id' => $intramurals->id,
            'ticket_category_id' => $intramurals->ticketCategories()->where('name', 'General Admission')->value('id'),
            'user_id' => $student->id,
            'attendee_type' => 'student',
            'status' => Registration::STATUS_CANCELLED,
        ]);

        try {
            $this->seed(MseufDemoEventsSeeder::class);
            $this->fail('The seeder should refuse an event that has registration history.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('registration', strtolower($exception->getMessage()));
        }

        $this->assertSame('Keep this existing local draft.', $yearEnd->fresh()->description);
        $this->assertSame(3, Event::count());
    }

    public function test_fixture_refuses_prepared_snapshots_and_transferred_authority(): void
    {
        $this->seed(MseufDemoEventsSeeder::class);
        $event = Event::where('slug', 'mseuf-university-intramurals-2026-demo')->sole();
        $snapshotId = (string) Str::uuid();

        DB::table('event_snapshots')->insert([
            'id' => $snapshotId,
            'event_id' => $event->id,
            'version' => 1,
            'schema_version' => 1,
            'sha256' => str_repeat('a', 64),
            'payload_ciphertext' => 'test-only-ciphertext',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $event->update(['snapshot_version' => 1, 'snapshot_prepared_at' => now()]);

        try {
            $this->seed(MseufDemoEventsSeeder::class);
            $this->fail('The seeder should refuse an event with a prepared snapshot.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('snapshot', strtolower($exception->getMessage()));
        }

        $event->update(['snapshot_prepared_at' => null]);
        DB::table('node_event_authorities')->insert([
            'cloud_event_id' => $event->id,
            'event_id' => $event->id,
            'snapshot_id' => $snapshotId,
            'version' => 1,
            'sha256' => str_repeat('a', 64),
            'grant' => 'test-only-grant',
            'activated_at' => now(),
        ]);

        try {
            $this->seed(MseufDemoEventsSeeder::class);
            $this->fail('The seeder should refuse an event with transferred authority.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('authorit', strtolower($exception->getMessage()));
        }

        $this->assertSame('MSEUF University Intramurals 2026 — Demo', $event->fresh()->title);
    }

    public function test_fixture_requires_opt_in_local_runtime_and_non_neon_database(): void
    {
        config(['ingress.mseuf_demo_events.enabled' => false]);
        try {
            $this->seed(MseufDemoEventsSeeder::class);
            $this->fail('The fixture requires explicit opt-in.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('opt-in', strtolower($exception->getMessage()));
        }

        config(['ingress.mseuf_demo_events.enabled' => true, 'ingress.mode' => 'node']);
        try {
            $this->seed(MseufDemoEventsSeeder::class);
            $this->fail('The fixture must refuse Event Node runtime.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('local cloud', strtolower($exception->getMessage()));
        }

        config(['ingress.mode' => 'cloud', 'ingress.node_id' => 'venue-node-1']);
        try {
            $this->seed(MseufDemoEventsSeeder::class);
            $this->fail('The fixture must refuse any Event Node identity.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('local cloud', strtolower($exception->getMessage()));
        }
        config(['ingress.node_id' => null]);

        config(['ingress.mode' => 'cloud', 'app.env' => 'production']);
        try {
            $this->seed(MseufDemoEventsSeeder::class);
            $this->fail('The fixture must refuse production.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('local cloud', strtolower($exception->getMessage()));
        }

        config(['app.env' => 'testing', 'ingress.render_environment' => true]);
        try {
            $this->seed(MseufDemoEventsSeeder::class);
            $this->fail('The fixture must refuse Render.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('local cloud', strtolower($exception->getMessage()));
        }

        config([
            'ingress.render_environment' => false,
            'ingress.mode' => 'cloud',
            'database.connections.sqlite.host' => 'ep-example.ap-southeast-1.aws.neon.tech',
        ]);
        try {
            $this->seed(MseufDemoEventsSeeder::class);
            $this->fail('The fixture must refuse a Neon database.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('neon', strtolower($exception->getMessage()));
        }

        config(['database.connections.sqlite.host' => 'db.example.net']);
        try {
            $this->seed(MseufDemoEventsSeeder::class);
            $this->fail('The fixture must refuse any other non-local database host.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('local database', strtolower($exception->getMessage()));
        }

        $this->assertSame(0, Event::count());
    }

    public function test_fixture_refuses_to_reset_a_published_demo_event_to_draft(): void
    {
        $this->seed(MseufDemoEventsSeeder::class);
        $event = Event::where('slug', 'mseuf-year-end-party-2026-demo')->sole();
        $event->update(['status' => Event::STATUS_PUBLISHED]);

        $this->expectException(LogicException::class);
        $this->seed(MseufDemoEventsSeeder::class);
    }

    public function test_student_only_fixture_rejects_a_public_guest_registration(): void
    {
        $this->seed(MseufDemoEventsSeeder::class);
        Carbon::setTestNow('2026-11-15 23:00:00');

        $event = Event::where('slug', 'mseuf-university-intramurals-2026-demo')->sole();
        $event->update(['status' => Event::STATUS_PUBLISHED]);
        $category = $event->ticketCategories()->where('name', 'General Admission')->firstOrFail();

        $this->post(route('public.events.register.store', $event->slug), [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Synthetic Guest',
            'guest_email' => 'guest@example.test',
        ])->assertRedirect(route('student.auth'));

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_public_miss_enverga_allows_guest_registration_and_ticket_without_firebase_identity(): void
    {
        $this->seed(MseufDemoEventsSeeder::class);
        Carbon::setTestNow('2026-11-16 00:05:00');

        $event = Event::where('slug', 'miss-enverga-university-2026-demo')->sole();
        $event->update(['status' => Event::STATUS_PUBLISHED]);
        $category = $event->ticketCategories()->where('name', 'Supporter / Alumni Pass')->firstOrFail();

        $response = $this->post(route('public.events.register.store', $event->slug), [
            'ticket_category_id' => $category->id,
            'guest_name' => 'Synthetic Public Guest',
            'guest_email' => 'guest@example.test',
        ]);

        $registration = Registration::query()->sole();
        $ticket = Ticket::query()->sole();

        $response->assertRedirect(route('public.tickets.show', $registration->id));
        $this->assertNull($registration->user_id);
        $this->assertSame('guest', $registration->attendee_type);
        $this->assertSame($category->id, $registration->ticket_category_id);
        $this->assertNotNull($ticket->getQrPayload());
        $this->get(route('public.tickets.show', $registration->id))->assertOk()->assertSee('<svg', false);
    }

    private function assertEvent(
        Event $event,
        string $title,
        string $venueCode,
        int $capacity,
        string $eligibility,
        bool $reEntry,
        array $utcSchedule,
    ): void {
        $this->assertSame($title, $event->title);
        $this->assertSame($venueCode, Venue::findOrFail($event->venue_id)->code);
        $this->assertSame($capacity, $event->capacity);
        $this->assertSame(Event::STATUS_DRAFT, $event->status);
        $this->assertSame($eligibility, $event->registration_eligibility);
        $this->assertSame($reEntry, $event->re_entry_allowed);
        $this->assertFalse($event->ticket_transfers_allowed);
        $this->assertSame(Event::IDENTITY_STANDARD, $event->identity_verification_level);
        $this->assertFalse($event->simulated_payments_enabled);
        $this->assertSame($this->organizer->id, $event->organizer_id);

        foreach ($utcSchedule as $column => $expected) {
            $this->assertSame($expected, $event->getRawOriginal($column), $column.' is not the expected UTC value.');
        }
    }

    private function assertCategory(Event $event, string $name, string $eligibility, int $capacity): void
    {
        $category = $event->ticketCategories()->where('name', $name)->sole();

        $this->assertSame(0, $category->price_minor);
        $this->assertSame('PHP', $category->currency);
        $this->assertSame($eligibility, $category->eligibility);
        $this->assertSame($capacity, $category->capacity);
        $this->assertTrue($category->is_active);
        $this->assertSame($name === 'General Admission', $category->is_default);
    }

    /** @param array<string, string> $gates */
    private function assertGates(Event $event, array $gates): void
    {
        $actual = $event->accessPoints()
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('name', 'code')
            ->all();

        $this->assertSame($gates, $actual);
    }
}
