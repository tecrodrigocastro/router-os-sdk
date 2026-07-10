<?php

/**
 * Shared config for the example scripts — reads connection details from
 * environment variables only, never hardcoded, so these examples are safe
 * to commit publicly. Set them before running:
 *
 *   ROUTEROS_HOST=192.168.88.1 ROUTEROS_USER=admin ROUTEROS_PASS=secret php examples/basic-usage.php
 *
 * (On Windows PowerShell: $env:ROUTEROS_HOST="..."; php examples/basic-usage.php)
 */

require __DIR__ . '/../vendor/autoload.php';

function exampleConfig(): array
{
    $host = getenv('ROUTEROS_HOST');
    $user = getenv('ROUTEROS_USER');
    $pass = getenv('ROUTEROS_PASS');

    if (false === $host || false === $user || false === $pass) {
        fwrite(STDERR, "Set ROUTEROS_HOST, ROUTEROS_USER and ROUTEROS_PASS environment variables first.\n");
        fwrite(STDERR, "Example: ROUTEROS_HOST=192.168.88.1 ROUTEROS_USER=admin ROUTEROS_PASS=secret php " . basename($_SERVER['SCRIPT_NAME']) . "\n");
        exit(1);
    }

    return [
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'tls'  => filter_var(getenv('ROUTEROS_TLS') ?: 'false', FILTER_VALIDATE_BOOL),
    ];
}
