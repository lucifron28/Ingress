<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\MseufDemoEventsProvisioner;
use Illuminate\Database\Seeder;
use LogicException;

class MseufDemoEventsSeeder extends Seeder
{
    public function run(): void
    {
        $organizer = $this->assertLocalDemoTarget();
        app(MseufDemoEventsProvisioner::class)->prepareDrafts($organizer);

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
}
