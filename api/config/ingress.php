<?php

return [
    'mode' => env('INGRESS_RUNTIME', 'legacy'),
    'node_id' => env('INGRESS_NODE_ID'),
    'node_sync_key' => env('INGRESS_NODE_SYNC_KEY'),
    'authority_private_key' => env('INGRESS_AUTHORITY_PRIVATE_KEY'),
    'authority_public_key' => env('INGRESS_AUTHORITY_PUBLIC_KEY'),
];
