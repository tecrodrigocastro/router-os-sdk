# routeros-sdk

Async-capable PHP client for the Mikrotik RouterOS binary API.

Combines:
- The wire-protocol groundwork (length-prefix codec, query builder, modern +
  legacy login) proven in `evilfreelancer/routeros-api-php`.
- The concurrent tag-multiplexing model (multiple `/listen` + `=interval=N`
  streams and one-shot commands running simultaneously on a single TCP
  connection) proven in production by the MikroDash Node.js dashboard, plus
  its RouterOS 7.18+ protocol quirk fixes (`!empty`, unknown-tag packets,
  multi-block `!done` on wifi-qcom devices, interval-stream cycle boundaries).

## Status

Implemented and tested (64 tests, including a real end-to-end test over a
loopback TCP socket and a genuine two-Fiber concurrency test against a real
socket via `Reactor`):

- `Protocol/` — wire codec and sentence parsing
- `Transport/` — `StreamTransport` (real TCP/TLS), `FakeTransport` (test double)
- `Connection` — tag-multiplexed dispatcher on native PHP 8.1+ Fibers
- `Io/Reactor` — `stream_select`-based scheduler so a real socket read
  suspends only the Fiber waiting on it, instead of blocking the process —
  needed for genuine concurrent `write()`/`listen()` calls
- `Query`, `Config`, `Auth\Authenticator`, `Channel`, `Client`

Not yet built: a Swoole coroutine transport and Laravel/Hyperf integration
packages (deferred — need `ext-swoole`/those frameworks installed to verify
properly, which this environment doesn't have).

## Requirements

- PHP >= 8.1

## Install

```bash
composer install
composer test
```

## Usage

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

// Event-driven stream
$arpChannel = $client->listen('/ip/arp/listen');
while ($row = $arpChannel->wait()) {
    // one ARP table change per call
}

// =interval=N push stream (for print commands with no /listen variant)
$resources = $client->interval('/system/resource/print', 2);
while ($cycle = $resources->wait()) {
    // full snapshot every 2 seconds
}

$client->close();
```

Both `write()` and `listen()`/`interval()` work concurrently on the same
connection out of the box (see `tests/ConnectionTest.php`). For that
concurrency to hold against a *real* socket (rather than only in unit tests
against `FakeTransport`), pass a shared `RouterOS\Sdk\Io\Reactor` to
`Client::connect()` and drive it — see `Io/Reactor.php`'s docblock and
`tests/Io/ReactorConcurrencyTest.php` for the pattern. Under Hyperf/Swoole
or Laravel Octane, a future `SwooleTransport` will make this automatic
without needing to drive a Reactor by hand.
