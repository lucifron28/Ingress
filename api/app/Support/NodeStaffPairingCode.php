<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

class NodeStaffPairingCode
{
    public function payload(string $url, string $nodeId, string $token, string $expiresAt): string
    {
        $parts = parse_url($url);
        $configuredUrl = rtrim((string) config('app.url'), '/').'/api/v1';
        $lanDemoHttp = config('ingress.mode') === 'node'
            && config('ingress.lan_demo') === true
            && ($parts['scheme'] ?? null) === 'http'
            && ($parts['host'] ?? null) === config('ingress.node_bind_address')
            && rtrim($url, '/') === $configuredUrl;
        if (! is_array($parts)
            || (($parts['scheme'] ?? null) !== 'https' && ! $lanDemoHttp) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! preg_match('#\A(?:/[A-Za-z0-9_-]+)*/api/v1\z#D', $parts['path'] ?? '')
            || strlen($url) > 512 || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $nodeId)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $token)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $expiresAt)) {
            throw new InvalidArgumentException('Invalid Event Node pairing code fields.');
        }

        return json_encode([
            'v' => 1,
            'url' => rtrim($url, '/'),
            'node' => $nodeId,
            'token' => $token,
            'expires' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function svgDataUri(string $payload): string
    {
        $renderer = new ImageRenderer(new RendererStyle(600, 4), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($payload);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
