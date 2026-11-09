<?php

return [
    'mode' => env('INGRESS_RUNTIME', 'legacy'),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('INGRESS_TRUSTED_HOSTS', ''))))),
    'node_id' => env('INGRESS_NODE_ID'),
    'operator_allowed_cidrs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('INGRESS_OPERATOR_ALLOWED_CIDRS', '127.0.0.1/32,::1/128')),
    ))),
    'node_sync_key' => env('INGRESS_NODE_SYNC_KEY'),
    'cloud_sync_url' => env('INGRESS_CLOUD_SYNC_URL'),
    'authority_private_key' => env('INGRESS_AUTHORITY_PRIVATE_KEY'),
    'authority_public_key' => env('INGRESS_AUTHORITY_PUBLIC_KEY'),
    'mail_test_recipient' => env('INGRESS_MAIL_TEST_RECIPIENT'),
];
