# router-os-sdk

[![Tests](https://github.com/tecrodrigocastro/router-os-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/tecrodrigocastro/router-os-sdk/actions/workflows/tests.yml)
[![Packagist Version](https://img.shields.io/packagist/v/tecrodrigocastro/router-os-sdk.svg)](https://packagist.org/packages/tecrodrigocastro/router-os-sdk)
[![License](https://img.shields.io/github/license/tecrodrigocastro/router-os-sdk.svg)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/tecrodrigocastro/router-os-sdk.svg)](composer.json)

Async-capable PHP client for the **Mikrotik RouterOS binary API** — connect
to a router over TCP/TLS, run commands, and consume multiple real-time
streams (`/listen`, `=interval=N`) concurrently on a single connection.

## Why

RouterOS's own API multiplexes commands and streams over one TCP connection
using a `.tag` field, but no existing PHP client actually used that tag to
route responses — every one of them supports exactly one command in flight
at a time. This SDK adds that: a real tag-multiplexing dispatcher built on
native PHP 8.1+ Fibers, alongside a battle-tested wire protocol
implementation and the handful of RouterOS quirks (7.18+ empty replies,
multi-block responses on some wireless APs, interval-stream semantics) that
only show up once you push a client hard in production.

## Features

- **Real concurrency** — run a one-shot command and several `/listen` or
  `=interval=N` streams at the same time on one socket, not one at a time.
- **TCP and TLS** (RouterOS API-SSL, port 8729).
- **Modern and legacy login** — `=name=`/`=password=` (RouterOS ≥ 6.43) and
  the MD5 challenge-response scheme for older firmware.
- **Fluent query builder** for filters (`where()`), attributes (`equal()`),
  and RouterOS's `?#` operations.
- **No hard runtime dependencies** — the concurrency core runs on native
  PHP Fibers; a Swoole coroutine transport is planned as an opt-in upgrade,
  not a requirement.
- **RouterOS protocol quirks handled out of the box**: `!empty` replies
  (RouterOS 7.18+), unknown/expired tag packets, multi-block `!done`
  responses on some wireless APs, and the `!done`-as-cycle-boundary
  semantics of `=interval=N` streams.
- **Resilience**: `ManagedClient` reconnects with exponential backoff for
  long-running processes; the Laravel `RouterOsManager` auto-heals a dead
  connection on next use and fails fast (no full `connect_timeout` wait)
  if the router recently failed.

## Requirements

- PHP >= 8.1

## Install

```bash
composer require tecrodrigocastro/router-os-sdk
```

## Quick start

See the [`examples/`](examples/) directory for runnable scripts against a
real router (basic usage, streaming, and the concurrent `write()`+`listen()`
proof), plus a Laravel usage pattern.

```php
use RouterOS\Sdk\Client;

$client = Client::connect([
    'host' => '192.168.88.1',
    'user' => 'admin',
    'pass' => 'secret',
    'tls'  => true, // port defaults to 8729 when true, 8728 otherwise
]);

// One-shot command
$interfaces = $client->write('/interface/print');

// Event-driven stream — one payload per change
$arpChannel = $client->listen('/ip/arp/listen');
while ($row = $arpChannel->wait()) {
    // e.g. ['address' => '10.0.0.5', 'mac-address' => '...']
}

// =interval=N push stream, for print commands with no /listen variant
$resources = $client->interval('/system/resource/print', 2);
while ($cycle = $resources->wait()) {
    // full snapshot every 2 seconds
}

$client->close();
```

### Query builder

```php
use RouterOS\Sdk\Query;

$query = new Query('/interface/print');
$query->where('disabled', 'false')
      ->where('running', '=', 'true');

$running = $client->query($query);
```

### Laravel

The `ServiceProvider`/`Facade` are auto-discovered — just install the
package and publish the config:

```bash
php artisan vendor:publish --provider="RouterOS\Sdk\Integrations\Laravel\ServiceProvider" --tag=config
```

`config/router-os.php` follows the same `default` + `connections` shape as
`database.php`, so a second router is just another entry away. Then:

```php
use RouterOS\Sdk\Integrations\Laravel\Facade as RouterOs;

$interfaces = RouterOs::write('/interface/print');            // default connection
$interfaces = RouterOs::connection('secondary')->write(...);  // named connection
```

`RouterOsManager` (what the facade resolves to) auto-heals: a connection
that goes dead (`Client::isClosed()`) is rebuilt on the *next* call, which
matters for long-lived processes (queue workers, Octane) — but it never
silently retries the command that actually failed, since that could
double-execute a non-idempotent one (e.g. `/ip/address/add`) if the
command reached the router and only the reply was lost. If the router is
genuinely unreachable, further calls fail immediately (no full
`connect_timeout` wait) for `reconnectCooldownSeconds` (default 5) after a
failure, instead of every job/request paying the full timeout again.

### Resilience: reconnecting for long-running processes

For a daemon-style script or Artisan command that's meant to run forever
(not a request-scoped web/queue context — see the Laravel section above
for that), `ManagedClient` is the PHP equivalent of MikroDash's Node `ROS`
class `connectLoop()`: connect, hand a working `Client` to your setup code,
and if the connection dies, reconnect with exponential backoff and hand
over a fresh one again.

```php
use RouterOS\Sdk\ManagedClient;

$managed = new ManagedClient($config);

$managed->onConnected(function ($client) {
    $arp = $client->listen('/ip/arp/listen');
    while (true) {
        $row = $arp->wait(); // throws when the connection dies, ending this cycle
        // handle $row
    }
});

$managed->onDisconnected(function () {
    // e.g. log it — a reconnect (with backoff) is about to be attempted
});

$managed->run(); // blocks until $managed->stop() is called
```

`ManagedClient` doesn't try to be a generic scheduler — it only notices a
connection cycle ended (the callback returned, or threw) and reconnects.
For concurrent work inside a cycle (`write()` + `listen()` at once), pass a
`Reactor` to its constructor and drive your own Fiber + `Reactor::tick()`
loop inside the callback, same as `examples/concurrent-reactor.php`. See
`examples/managed-client.php` for a runnable version.

### Concurrency

`write()` and `listen()`/`interval()` already work concurrently against
each other out of the box (see `tests/ConnectionTest.php`). For that
concurrency to hold against a *real* socket — not just in unit tests
against the in-memory test double — pass a shared `RouterOS\Sdk\Io\Reactor`
to `Client::connect()` and drive it:

```php
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Io\Reactor;

$reactor = new Reactor();
$client  = Client::connect($config, $reactor);
```

See `Io/Reactor.php`'s docblock and `tests/Io/ReactorConcurrencyTest.php`
for the pattern — nothing drives the loop automatically in plain PHP, so
whoever wants several concurrent operations to progress needs to tick it.
Under Hyperf/Swoole or Laravel Octane, a planned `SwooleTransport` will make
this automatic instead.

## Testing

```bash
composer install
composer test
```

83 tests, including a real end-to-end test over a loopback TCP socket and a
genuine two-Fiber concurrency test against a real socket.

## Roadmap

- `SwooleTransport` + auto-detection, for coroutine-native concurrency
  under Hyperf and Laravel Octane (Swoole mode) with no manual `Reactor`
  driving required.
- Hyperf integration (`ConfigProvider` + coroutine connection pool).

Contributions on any of the above are welcome.

## Credits

Built on ideas and code from two prior projects:
- [`evilfreelancer/routeros-api-php`](https://github.com/EvilFreelancer/routeros-api-php)
  — the RouterOS wire protocol (length-prefix codec, query builder, login)
  this SDK's `Protocol/` and `Query`/`Config`/`Auth` layers are adapted from.
- MikroDash, a Node.js RouterOS dashboard whose production-hardened
  tag-multiplexing model and RouterOS quirk fixes shaped `Connection`'s
  design.

## License

[MIT](LICENSE)
