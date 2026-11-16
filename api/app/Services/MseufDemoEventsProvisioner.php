<?php

namespace App\Services;

use App\Models\Event;
use App\Models\TicketCategory;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** @internal Shared only by the guarded local seeder and explicit Neon command. */
final class MseufDemoEventsProvisioner
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

    public function prepareDrafts(User $organizer): void
    {
        if (! in_array($organizer->role, [User::ROLE_ORGANIZER, User::ROLE_ADMIN], true)) {
            throw new LogicException('MSEUF demo events require an existing organizer or administrator account.');
        }

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
    }

    /** @param array<string, mixed> $definition */
    private function venueFor(array $definition): Venue
    {
        $venue = Venue::query()->where('code', $definition['venue_code'])->first();

        if (! $venue || $venue->name !== $definition['venue_name']) {
            throw new LogicException('The required existing MSEUF venue is missing or does not match.');
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
            throw new LogicException('A demo event is no longer a draft; provisioning will not change its lifecycle state.');
        }

        if ($event->registrations()->exists()) {
            throw new LogicException('A demo event has registration history; provisioning will not overwrite it.');
        }

        if ($event->checkoutOrders()->exists()) {
            throw new LogicException('A demo event has checkout history; provisioning will not overwrite it.');
        }

        $hasAuthority = DB::table('cloud_event_authorities')->where('event_id', $event->id)->exists()
            || DB::table('node_event_authorities')
                ->where('event_id', $event->id)
                ->orWhere('cloud_event_id', $event->id)
                ->exists();

        if ($hasAuthority) {
            throw new LogicException('A demo event has transferred authority; provisioning will not overwrite it.');
        }

        if ($event->snapshot_prepared_at !== null || DB::table('event_snapshots')->where('event_id', $event->id)->exists()) {
            throw new LogicException('A demo event has a prepared snapshot; provisioning will not overwrite it.');
        }

        $hasAdmission = DB::table('admissions')
            ->join('tickets', 'tickets.id', '=', 'admissions.ticket_id')
            ->join('registrations', 'registrations.id', '=', 'tickets.registration_id')
            ->where('registrations.event_id', $event->id)
            ->exists();

        if ($hasAdmission) {
            throw new LogicException('A demo event has admission history; provisioning will not overwrite it.');
        }
    }

    private function utc(string $manilaDateTime): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $manilaDateTime, self::TIMEZONE)
            ->utc()
            ->format('Y-m-d H:i:s');
    }
}
