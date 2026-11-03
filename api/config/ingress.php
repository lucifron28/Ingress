<?php

return [
    'mode' => env('INGRESS_RUNTIME', 'legacy'),
    'node_id' => env('INGRESS_NODE_ID'),
    'node_sync_key' => env('INGRESS_NODE_SYNC_KEY'),
];
