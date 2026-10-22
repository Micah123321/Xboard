<?php

return [
    // Install the standalone helper on the panel/scheduler host, not the proxy node.
    'binary' => env('SERVER_ENTRY_PROBE_BINARY', base_path('bin/mi-entry-probe')),
    'target_url' => env('SERVER_ENTRY_PROBE_URL', 'https://www.gstatic.com/generate_204'),
    'timeout_seconds' => (int) env('SERVER_ENTRY_PROBE_TIMEOUT', 5),
    // Optional dedicated account; it must be in the runtime node's active user list.
    'user_id' => (int) env('SERVER_ENTRY_PROBE_USER_ID', 0),
];
