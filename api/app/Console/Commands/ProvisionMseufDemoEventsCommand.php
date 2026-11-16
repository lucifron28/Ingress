<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MseufDemoEventsProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class ProvisionMseufDemoEventsCommand extends Command
{
    protected $signature = 'ingress:provision-mseuf-demo-events
        {--target= : Must be shared-neon to confirm this shared database write}
        {--organizer-id= : Existing Cloud organizer or administrator ID to own the drafts}';

    protected $description = 'Provision the three MSEUF demo event drafts on the explicitly selected shared Neon database.';

    public function handle(MseufDemoEventsProvisioner $provisioner): int
    {
        if ($this->option('target') !== 'shared-neon') {
            $this->error('Pass --target=shared-neon to confirm this shared database write.');

            return self::FAILURE;
        }

        if (! config('ingress.mseuf_demo_events.shared_provisioning_enabled')) {
            $this->error('Enable INGRESS_MSEUF_SHARED_DEMO_EVENTS_ENABLED for this one-time provisioning command.');

            return self::FAILURE;
        }

        if (! $this->isLocalCloudCli()) {
            $this->error('This command only runs from a local Cloud CLI process, never Render or an Event Node.');

            return self::FAILURE;
        }

        if (! $this->isDirectNeonConnection()) {
            $this->error('Configure DB_URL for a direct Neon PostgreSQL connection using TLS before provisioning.');

            return self::FAILURE;
        }

        $organizerId = filter_var($this->option('organizer-id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($organizerId === false) {
            $this->error('Pass --organizer-id with an existing Cloud organizer or administrator account ID.');

            return self::FAILURE;
        }

        $organizer = User::query()->whereKey($organizerId)
            ->whereIn('role', [User::ROLE_ORGANIZER, User::ROLE_ADMIN])
            ->first();

        if (! $organizer) {
            $this->error('The selected ID is not an existing organizer or administrator account.');

            return self::FAILURE;
        }

        try {
            $provisioner->prepareDrafts($organizer);
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            Log::error('MSEUF demo event provisioning failed.', [
                'exception_class' => $exception::class,
            ]);
            $this->error('Provisioning failed. Check the local application log; connection details were omitted.');

            return self::FAILURE;
        }

        $this->info('Prepared the three MSEUF demo events as drafts on the selected Neon Cloud database.');

        return self::SUCCESS;
    }

    private function isLocalCloudCli(): bool
    {
        return config('app.env') === 'local'
            && config('ingress.mode') === 'cloud'
            && ! filled(config('ingress.node_id'))
            && ! filter_var(config('ingress.render_environment'), FILTER_VALIDATE_BOOL)
            && ! filled(config('ingress.render_service_id'));
    }

    private function isDirectNeonConnection(): bool
    {
        $database = (array) config('database.connections.'.config('database.default'), []);
        if (($database['driver'] ?? null) !== 'pgsql') {
            return false;
        }

        $url = (string) ($database['url'] ?? '');
        if ($url === '') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! is_string($host) || ! in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true)) {
            return false;
        }

        $host = strtolower($host);
        if (! str_ends_with($host, '.neon.tech') || str_contains($host, '-pooler')) {
            return false;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $parameters);
        $sslMode = $parameters['sslmode'] ?? null;

        return in_array($sslMode, ['require', 'verify-ca', 'verify-full'], true);
    }
}
