<?php

return [
    'mode' => env('INGRESS_RUNTIME', 'legacy'),
    'lan_demo' => env('INGRESS_NODE_LAN_DEMO', false),
    'display_timezone' => env('INGRESS_DISPLAY_TIMEZONE', 'Asia/Manila'),
    'node_bind_address' => env('INGRESS_NODE_BIND_ADDRESS'),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('INGRESS_TRUSTED_HOSTS', ''))))),
    'node_id' => env('INGRESS_NODE_ID'),
    'operator_allowed_cidrs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('INGRESS_OPERATOR_ALLOWED_CIDRS', '127.0.0.1/32,::1/128')),
    ))),
    'operator_gateway' => env('INGRESS_OPERATOR_GATEWAY'),
    'node_sync_key' => env('INGRESS_NODE_SYNC_KEY'),
    'cloud_sync_url' => env('INGRESS_CLOUD_SYNC_URL'),
    'authority_private_key' => env('INGRESS_AUTHORITY_PRIVATE_KEY'),
    'authority_public_key' => env('INGRESS_AUTHORITY_PUBLIC_KEY'),
    'render_environment' => env('RENDER', false),
    'render_service_id' => env('RENDER_SERVICE_ID'),
    'mseuf_demo_events' => [
        'enabled' => env('INGRESS_MSEUF_DEMO_EVENTS_ENABLED', false),
        'organizer_id' => env('INGRESS_MSEUF_DEMO_EVENTS_ORGANIZER_ID'),
    ],
    'mail_test_recipient' => env('INGRESS_MAIL_TEST_RECIPIENT'),
    'simulated_payments' => [
        'enabled' => env('INGRESS_SIMULATED_PAYMENTS_ENABLED', false),
        'reservation_minutes' => 10,
    ],
];
