<?php

return [
    'enabled' => env('NYA_ENTRY_SYNC_ENABLED', false),
    'base_url' => env('NYA_BASE_URL', 'https://nya.trp.sh'),
    'username' => env('NYA_USERNAME', ''),
    'password' => env('NYA_PASSWORD', ''),
    'proxy' => env('NYA_PROXY', ''),
    'timeout' => env('NYA_ENTRY_SYNC_TIMEOUT', 20),

    // Only explicitly selected nodes and their matching connection ports are managed.
    'server_ids' => explode(',', (string) env('NYA_ENTRY_SYNC_SERVER_IDS', '')),
    'ports' => explode(',', (string) env('NYA_ENTRY_SYNC_PORTS', '')),
];
