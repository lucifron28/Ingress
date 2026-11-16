<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\TicketCategory;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class MseufDemoEventsSeeder extends Seeder
{
    private const TIMEZONE = 'Asia/Manila';

    /** @var list<array<string, mixed>> */
    private const EVENTS = [
        [
            'slug' => 'mseuf-university-intramurals-2026-demo',
            'title' => 'MSEUF University Intramurals 2026 — Demo',
            'venue_code' => 'GYM',
            'venue_name' => 'MSEUF University Gymnasium',
            'capacity' => 500,
            'registration_eligibility' => Event::ELIGIBILITY_STUDENT,
            're_entry_allowed' => false,
            'registration_opens_at' => '2026-11-15 08:00',
            'registration_closes_at' => '2026-11-16 08:00',
            'starts_at' => '2026-11-16 09:00',
            'ends_at' => '2026-11-16 22:00',
            'description' => 'Celebrate Envergan unity and sportsmanship through athletic competitions, spirited college rivalries, team performances, and activities that bring the university community together. Students are invited to support their respective colleges and celebrate teamwork, determination, and school pride.',
            'categories' => [
                ['name' => 'General Admission', 'eligibility' => TicketCategory::ELIGIBILITY_STUDENT, 'capacity' => 500, 'is_default' => true],
            ],
            'access_points' => [
                ['name' => 'Main Entrance', 'code' => 'MAIN'],
                ['name' => 'Gate A', 'code' => 'GATE-A'],
                ['name' => 'Gate B', 'code' => 'GATE-B'],
            ],
        ],
        [
            'slug' => 'mseuf-year-end-party-2026-demo',
            'title' => 'MSEUF Year-End Party 2026 — Demo',
            'venue_code' => 'MPH',
            'venue_name' => 'Enverga Multipurpose Hall',
            'capacity' => 200,
            'registration_eligibility' => Event::ELIGIBILITY_STUDENT,
            're_entry_allowed' => true,
            'registration_opens_at' => '2026-11-16 08:00',
            'registration_closes_at' => '2026-11-20 16:00',
            'starts_at' => '2026-11-20 17:00',
            'ends_at' => '2026-11-20 22:00',
            'description' => 'Celebrate the year\'s achievements, friendships, and memorable experiences at the MSEUF Year-End Party. Join fellow Envergans for an evening of music, performances, interactive activities, and university spirit. This is a university-wide simulation, not a CCMS Year-End Party.',
            'categories' => [
                ['name' => 'General Admission', 'eligibility' => TicketCategory::ELIGIBILITY_STUDENT, 'capacity' => 200, 'is_default' => true],
            ],
            'access_points' => [
                ['name' => 'Main Entrance', 'code' => 'MAIN'],
            ],
        ],
        [
            'slug' => 'miss-enverga-university-2026-demo',
            'title' => 'Miss Enverga University 2026 — Demo',
            'venue_code' => 'GYM',
            'venue_name' => 'MSEUF University Gymnasium',
            'capacity' => 400,
            'registration_eligibility' => Event::ELIGIBILITY_PUBLIC,
            're_entry_allowed' => false,
            'registration_opens_at' => '2026-11-16 08:00',
            'registration_closes_at' => '2026-11-18 17:00',
            'starts_at' => '2026-11-18 18:00',
            'ends_at' => '2026-11-18 22:00',
            'description' => 'Witness elegance, confidence, intelligence, and Envergan excellence at Miss Enverga University 2026. Celebrate outstanding representatives as they showcase their talents, advocacies, and dedication to the Envergan community. Students, alumni, families, and outside supporters are welcome.',
            'categories' => [
                ['name' => 'General Admission', 'eligibility' => TicketCategory::ELIGIBILITY_PUBLIC, 'capacity' => 300, 'is_default' => true],
                ['name' => 'Supporter / Alumni Pass', 'eligibility' => TicketCategory::ELIGIBILITY_PUBLIC, 'capacity' => 100, 'is_default' => false],
            ],
            'access_points' => [
                ['name' => 'Main Entrance', 'code' => 'MAIN'],
                ['name' => 'Gate A', 'code' => 'GATE-A'],
            ],
        ],
    ];

    public function run(): void
    {
        $organizer = $this->assertLocalDemoTarget();

        $venues = collect(self::EVENTS)
            ->mapWithKeys(fn (array $definition): array => [$definition['venue_code'] => $this->venueFor($definition)])
            ->all();

        DB::transaction(function () use ($organizer, $venues): void {
            $events = Event::query()
                ->whereIn('slug', array_column(self::EVENTS, 'slug'))
                ->lockForUpdate()
                ->get()
                ->keyBy('slug');

            foreach (self::EVENTS as $definition) {
                $this->assertSafeToPrepare($events->get($definition['slug']), $definition);
            }

            foreach (self::EVENTS as $definition) {
                $event = $events->get($definition['slug']) ?? new Event;
                $event->fill([
                    'organizer_id' => $organizer->id,
                    'venue_id' => $venues[$definition['venue_code']]->id,
                    'title' => $definition['title'],
                    'slug' => $definition['slug'],
                    'description' => $definition['description'],
                    'capacity' => $definition['capacity'],
                    'registration_eligibility' => $definition['registration_eligibility'],
                    'simulated_payments_enabled' => false,
                    'registration_opens_at' => $this->utc($definition['registration_opens_at']),
                    'registration_closes_at' => $this->utc($definition['registration_closes_at']),
                    'registration_closed_at' => null,
                    'starts_at' => $this->utc($definition['starts_at']),
                    'ends_at' => $this->utc($definition['ends_at']),
                    're_entry_allowed' => $definition['re_entry_allowed'],
                    'identity_verification_level' => Event::IDENTITY_STANDARD,
                    'ticket_transfers_allowed' => false,
                    'status' => Event::STATUS_DRAFT,
                ]);
                $event->save();

                foreach ($definition['categories'] as $categoryDefinition) {
                    $category = $event->ticketCategories()->firstOrNew(['name' => $categoryDefinition['name']]);
                    $category->fill([
                        'price_minor' => 0,
                        'currency' => 'PHP',
                        'capacity' => $categoryDefinition['capacity'],
                        'eligibility' => $categoryDefinition['eligibility'],
                        'is_active' => true,
                        'is_default' => $categoryDefinition['is_default'],
                    ]);
                    $category->save();
                }

                foreach ($definition['access_points'] as $accessPointDefinition) {
                    $event->accessPoints()->updateOrCreate(
                        ['code' => $accessPointDefinition['code']],
                        ['name' => $accessPointDefinition['name'], 'is_active' => true],
                    );
                }
            }
        });

        $this->command?->info('Prepared three local MSEUF demo events as drafts.');
    }

    private function assertLocalDemoTarget(): User
    {
        if (! config('ingress.mseuf_demo_events.enabled')) {
            throw new LogicException('Enable the explicit local MSEUF demo-events opt-in before running this seeder.');
        }

        if (! in_array(config('app.env'), ['local', 'testing'], true)
            || config('ingress.mode') !== 'cloud'
            || filled(config('ingress.node_id'))
            || filter_var(config('ingress.render_environment'), FILTER_VALIDATE_BOOL)
            || filled(config('ingress.render_service_id'))) {
            throw new LogicException('MSEUF demo events may only be seeded in a local cloud application, never production, Render, or an Event Node.');
        }

        $database = (array) config('database.connections.'.config('database.default'), []);
        $databaseUrl = (string) ($database['url'] ?? '');
        $urlHost = $databaseUrl === '' ? null : parse_url($databaseUrl, PHP_URL_HOST);
        if ($databaseUrl !== '' && ! is_string($urlHost)) {
            throw new LogicException('MSEUF demo events require a local database connection.');
        }

        $hosts = [
            $database['host'] ?? null,
            $urlHost,
        ];
        $localDatabaseHosts = ['127.0.0.1', 'localhost', '::1', 'host.docker.internal'];

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host, '[]'));
            if ($host === 'neon.tech' || str_ends_with($host, '.neon.tech')) {
                throw new LogicException('MSEUF demo events cannot be seeded into a Neon database.');
            }

            if ($host !== '' && ! in_array($host, $localDatabaseHosts, true)) {
                throw new LogicException('MSEUF demo events require a local database connection.');
            }
        }

        if (($database['driver'] ?? null) !== 'sqlite' && count(array_filter($hosts)) === 0) {
            throw new LogicException('MSEUF demo events require a local database connection.');
        }

        $organizerId = config('ingress.mseuf_demo_events.organizer_id');
        $organizer = filled($organizerId)
            ? User::query()->whereKey($organizerId)
                ->whereIn('role', [User::ROLE_ORGANIZER, User::ROLE_ADMIN])
                ->first()
            : null;

        if (! $organizer) {
            throw new LogicException('Set INGRESS_MSEUF_DEMO_EVENTS_ORGANIZER_ID to an existing local organizer or administrator account.');
        }

        return $organizer;
    }

    /** @param array<string, mixed> $definition */
    private function venueFor(array $definition): Venue
    {
        $venue = Venue::query()->where('code', $definition['venue_code'])->first();

        if (! $venue || $venue->name !== $definition['venue_name']) {
            throw new LogicException('Run VenueSeeder locally first; the required existing MSEUF venue is missing or does not match.');
        }

        return $venue;
    }

    /** @param array<string, mixed> $definition */
    private function assertSafeToPrepare(?Event $event, array $definition): void
    {
        if (! $event) {
            return;
        }

        if ($event->title !== $definition['title']) {
            throw new LogicException('A demo event slug already belongs to a different event; no demo data was changed.');
        }

        if ($event->status !== Event::STATUS_DRAFT) {
            throw new LogicException('A demo event is no longer a draft; the seeder will not change its lifecycle state.');
        }

        if ($event->registrations()->exists()) {
            throw new LogicException('A demo event has registration history; the seeder will not overwrite it.');
        }

        if ($event->checkoutOrders()->exists()) {
            throw new LogicException('A demo event has checkout history; the seeder will not overwrite it.');
        }

        $hasAuthority = DB::table('cloud_event_authorities')->where('event_id', $event->id)->exists()
            || DB::table('node_event_authorities')
                ->where('event_id', $event->id)
                ->orWhere('cloud_event_id', $event->id)
                ->exists();

        if ($hasAuthority) {
            throw new LogicException('A demo event has transferred authority; the seeder will not overwrite it.');
        }

        if ($event->snapshot_prepared_at !== null || DB::table('event_snapshots')->where('event_id', $event->id)->exists()) {
            throw new LogicException('A demo event has a prepared snapshot; the seeder will not overwrite it.');
        }

        $hasAdmission = DB::table('admissions')
            ->join('tickets', 'tickets.id', '=', 'admissions.ticket_id')
            ->join('registrations', 'registrations.id', '=', 'tickets.registration_id')
            ->where('registrations.event_id', $event->id)
            ->exists();

        if ($hasAdmission) {
            throw new LogicException('A demo event has admission history; the seeder will not overwrite it.');
        }
    }

    private function utc(string $manilaDateTime): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $manilaDateTime, self::TIMEZONE)
            ->utc()
            ->format('Y-m-d H:i:s');
    }
}
