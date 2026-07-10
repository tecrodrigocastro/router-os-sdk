<?php

/**
 * Illustrative only — this file is NOT meant to be run directly with
 * `php examples/laravel-usage.php` (there's no Laravel app to bootstrap
 * here). It shows the code you'd actually write inside a real Laravel app
 * once the package is installed via Composer.
 *
 * Setup, once per app:
 *
 *   composer require tecrodrigocastro/router-os-sdk
 *   php artisan vendor:publish --provider="RouterOS\Sdk\Integrations\Laravel\ServiceProvider" --tag=config
 *
 * Then fill in config/router-os.php (or the ROUTEROS_* env vars it reads
 * by default) and use the Facade or the manager wherever you need it —
 * a controller, a queue job, an Artisan command, all work the same way.
 */

use RouterOS\Sdk\Integrations\Laravel\Facade as RouterOs;
use RouterOS\Sdk\Integrations\Laravel\RouterOsManager;

// Default connection (config/router-os.php's "default" key):
$interfaces = RouterOs::write('/interface/print');

// A named connection, e.g. a second router configured under
// config/router-os.php's "connections" array:
$secondaryInterfaces = RouterOs::connection('secondary')->write('/interface/print');

// Streaming works the same way as plain PHP usage — useful in a queue job
// or a long-running Artisan command:
$arp = RouterOs::listen('/ip/arp/listen');
while ($row = $arp->wait()) {
    // handle each ARP change, e.g. dispatch an event, update a cache, ...
}

// Prefer dependency injection over the facade in classes you write
// yourself (e.g. a job or a service) — resolve RouterOsManager from the
// container instead:
final class SyncRouterInterfaces
{
    public function __construct(private readonly RouterOsManager $routerOs)
    {
    }

    public function handle(): array
    {
        return $this->routerOs->write('/interface/print');
    }
}

// If a connection goes stale (e.g. the router rebooted, or the process has
// been running for a long time as a queue worker/Octane), the manager
// rebuilds it automatically on the *next* call — but it never silently
// retries a command that already failed, since that risks double-executing
// a non-idempotent one (e.g. /ip/address/add) if it reached the router and
// only the reply was lost:
try {
    RouterOs::write('/ip/address/add', ['=address=10.0.0.5/24', '=interface=ether2']);
} catch (\RouterOS\Sdk\Exceptions\TransportException $e) {
    // Decide here whether *this specific* command is safe to retry.
    // The next RouterOs::... call will use a fresh connection regardless.
}
