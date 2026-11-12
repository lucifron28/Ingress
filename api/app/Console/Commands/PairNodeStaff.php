<?php

namespace App\Console\Commands;

use App\Actions\NodeStaffPairing;
use App\Support\NodeStaffPairingCode;
use BaconQrCode\Renderer\PlainTextRenderer;
use BaconQrCode\Writer;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class PairNodeStaff extends Command
{
    protected $signature = 'ingress:pair-node-staff {cloud_user_id} {--url= : Event Node API URL}';

    protected $description = 'Display a short-lived one-use pairing QR for an assigned staff member';

    public function handle(NodeStaffPairing $pairing, NodeStaffPairingCode $pairingCode): int
    {
        $id = $this->argument('cloud_user_id');
        $url = $this->option('url');
        $parts = is_string($url) ? parse_url($url) : false;
        $nodeId = config('ingress.node_id');
        $lanDemoHttp = config('ingress.mode') === 'node'
            && config('ingress.lan_demo') === true
            && is_array($parts)
            && ($parts['scheme'] ?? null) === 'http'
            && ($parts['host'] ?? null) === config('ingress.node_bind_address')
            && rtrim((string) $url, '/') === rtrim((string) config('app.url'), '/').'/api/v1';
        if (! is_string($id) || ! preg_match('/\A[1-9][0-9]*\z/', $id) || (string) (int) $id !== $id
            || ! is_string($url) || strlen($url) > 512 || ! is_array($parts)
            || (($parts['scheme'] ?? null) !== 'https' && ! $lanDemoHttp)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! preg_match('#\A(?:/[A-Za-z0-9_-]+)*/api/v1/?\z#', $parts['path'] ?? '') || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! is_string($nodeId) || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $nodeId)) {
            $this->error('Provide a valid cloud user ID and configured Event Node API URL ending in /api/v1.');

            return self::FAILURE;
        }
        try {
            $issued = $pairing->issue((int) $id);
        } catch (ValidationException) {
            $this->error('Staff member needs a current node assignment and local credential.');

            return self::FAILURE;
        }
        $payload = $pairingCode->payload(rtrim($url, '/'), $nodeId, $issued['token'], $issued['expires_at']);
        $qr = (new Writer(new PlainTextRenderer(4)))->writeString($payload);
        $lines = array_values(array_filter(explode("\n", $qr), fn ($line) => $line !== ''));
        $border = str_repeat(' ', mb_strlen($lines[0] ?? ''));
        $this->info("Pair {$issued['user']->name} ({$issued['user']->email}) with node {$nodeId}. Expires {$issued['expires_at']}.");
        $this->line('Show this code only to that staff member. It can be used once.');
        $this->output->write("\033[30;47m".$border."\n".implode("\n", $lines)."\n".$border."\033[0m\n");

        return self::SUCCESS;
    }
}
