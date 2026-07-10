<?php

// Every key here maps straight through to RouterOS\Sdk\Config's accepted
// parameters (host/user/pass/port/tls/legacy/...) — see src/Config.php.

return [
    'default' => env('ROUTEROS_CONNECTION', 'main'),

    'connections' => [
        'main' => [
            'host'   => env('ROUTEROS_HOST', '192.168.88.1'),
            'user'   => env('ROUTEROS_USER', 'admin'),
            'pass'   => env('ROUTEROS_PASS', ''),
            'port'   => env('ROUTEROS_PORT'),
            'tls'    => env('ROUTEROS_TLS', true),
            'legacy' => env('ROUTEROS_LEGACY', false),
        ],
    ],
];
