<?php

namespace App\Console\Commands;

use App\Actions\NodeStaffPairing;
use BaconQrCode\Renderer\PlainTextRenderer;
use BaconQrCode\Writer;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class PairNodeStaff extends Command
{
    protected $signature = 'ingress:pair-node-staff {cloud_user_id} {--url= : HTTPS node API URL}';

    protected $description = 'Display a short-lived one-use pairing QR for an assigned staff member';

    public function handle(NodeStaffPairing $pairing): int
    {
        $id = $this->argument('cloud_user_id');
        $url = $this->option('url');
        $parts = is_string($url) ? parse_url($url) : false;
        $nodeId = config('ingress.node_id');
        if (! is_string($id) || ! preg_match('/\A[1-9][0-9]*\z/', $id) || (string) (int) $id !== $id
            || ! is_string($url) || strlen($url) > 512 || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! preg_match('#\A(?:/[A-Za-z0-9_-]+)*/api/v1/?\z#', $parts['path'] ?? '') || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! is_string($nodeId) || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $nodeId)) {
            $this->error('Provide a valid cloud user ID and HTTPS node API URL ending in /api/v1.');

            return self::FAILURE;
        }
        try {
            $issued = $pairing->issue((int) $id);
        } catch (ValidationException) {
            $this->error('Staff member needs a current node assignment and local credential.');

            return self::FAILURE;
        }
        $payload = json_encode(['v' => 1, 'url' => rtrim($url, '/'), 'node' => $nodeId,
            'token' => $issued['token'], 'expires' => $issued['expires_at']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $qr = (new Writer(new PlainTextRenderer(4)))->writeString($payload);
        $lines = array_values(array_filter(explode("\n", $qr), fn ($line) => $line !== ''));
        $border = str_repeat(' ', mb_strlen($lines[0] ?? ''));
        $this->info("Pair {$issued['user']->name} ({$issued['user']->email}) with node {$nodeId}. Expires {$issued['expires_at']}.");
        $this->line('Show this code only to that staff member. It can be used once.');
        $this->output->write("\033[30;47m".$border."\n".implode("\n", $lines)."\n".$border."\033[0m\n");

        return self::SUCCESS;
    }
}
