# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.6.0] - 2026-07-10

### Added

- `RouterOS\Sdk\Isp\RadiusBootstrapScript`: generates a `.rsc` script that
  registers a FreeRADIUS server as the PPP AAA backend on a fresh router
  (`/radius add` + `/ppp aaa set use-radius=yes`) — pure string templating,
  no connection needed, same shape as `Vpn\WireGuardBootstrapScript`.
- `examples/pppoe-lab.php`: interactive PPPoE lab test script — creates a
  customer secret, waits for a real CPE to dial in, then walks through
  `suspend()`/`activate()` against a live session.

## [0.5.0] - 2026-07-10

### Added

- `RouterOS\Sdk\Vpn\WireGuard`: configure RouterOS 7's native WireGuard
  support (interface + peers) — `Client::wireGuard($interfaceName)`.
  `WireGuard::generateKeypair()` (requires `ext-sodium`, suggested not
  required) for when you need a keypair before the router has one.
- `RouterOS\Sdk\Vpn\WireGuardBootstrapScript`: generates a `.rsc` script
  for bootstrapping WireGuard on a router with no connectivity yet (pure
  string templating, no connection needed).
- `RouterOS\Sdk\Isp\Customer`: unified `suspend()`/`activate()` touching
  address-list, PPP secret, and queue — each action runs independently
  (one failing doesn't stop the others), returning a `CustomerActionResult`
  with per-action success/failure.
- `RouterOS\Sdk\Isp\PppProfiles`, `QueueTree`, `Firewall` (idempotent rule
  installation keyed by comment) — `Client::pppProfiles()`/`queueTree()`/`firewall()`.
- `PppSecrets::disable()`/`enable()`, `SimpleQueue::disable()`/`enable()`.

## [0.4.0] - 2026-07-10

### Added

- `Client::registerConnection()`-equivalent for the Laravel integration:
  `RouterOsManager::registerConnection()`/`hasConnection()`/
  `forgetConnectionConfig()` — register/deregister a connection at
  runtime, for routers stored as database rows rather than a static
  `config/router-os.php` list.
- `Client::findWhere()`/`findOne()`/`removeWhere()`/`setWhere()` — generic
  helpers for RouterOS's near-universal `add`/`print`/`set`/`remove`
  resource convention (find by filter, then act on the matched `.id`),
  built entirely on the existing `Query`/`write()` primitives.
- `RouterOS\Sdk\Isp\*` toolkit: `PppSecrets` (PPPoE secrets + active
  sessions), `AddressList` (firewall address-list blocking, e.g.
  delinquent customers), `SimpleQueue` (bandwidth shaping) — exposed via
  `Client::pppSecrets()`/`addressList()`/`simpleQueue()`.
- `examples/isp-toolkit.php`.

## [0.3.1] - 2026-07-10

### Changed

- **Package renamed on Packagist**: `tecrodrigocastro/router-os-sdk` →
  `redrodrigo/router-os-sdk`. Update your `composer.json` accordingly
  (`composer remove tecrodrigocastro/router-os-sdk && composer require redrodrigo/router-os-sdk`).
  The GitHub repository itself is unchanged
  (github.com/tecrodrigocastro/router-os-sdk).

## [0.3.0] - 2026-07-10

### Added

- `ManagedClient`: a supervisor for long-running processes (daemon
  scripts, Artisan commands) that connects, hands a working `Client` to
  `onConnected()`, and reconnects with exponential backoff if the
  connection dies — the PHP equivalent of MikroDash's `ROS.connectLoop()`.
- `RouterOsManager` (Laravel integration): a reconnect cooldown —
  after a connection failure, further attempts for that connection name
  fail immediately (`reconnectCooldownSeconds`, default 5) instead of each
  one paying a full `connect_timeout`, which matters under sustained queue
  worker throughput when the router is genuinely down.
- `RouterOS\Sdk\Exceptions\RecoverableException`: a marker interface
  (implemented by `TransportException` and `BadCredentialsException`)
  distinguishing transient connection/auth failures worth retrying from
  programming errors (`ConfigException`, `QueryException`) that shouldn't be.
- `examples/managed-client.php`.

## [0.2.2] - 2026-07-10

### Added

- `examples/` directory: runnable scripts for basic usage, event-driven
  and interval streaming, the real write()+listen() concurrency proof via
  `Io\Reactor`, and a Laravel Facade/DI usage pattern. Credentials come
  from environment variables, never hardcoded.

## [0.2.1] - 2026-07-10

### Fixed

- CI failures on Windows: narrowed `orchestra/testbench` to `^8.0|^9.0`
  (`^10.0` requires `phpunit/phpunit` `^11.x`, conflicting with our own
  `^10.5`), and explicitly enabled the `fileinfo` PHP extension in the CI
  setup step (`laravel/framework` 11.34+ requires it via
  `league/flysystem-local`, and it isn't always enabled by default,
  especially on Windows). Dev/CI-only — no change for package consumers.

## [0.2.0] - 2026-07-10

### Added

- Laravel integration: `ServiceProvider`/`Facade` (auto-discovered),
  `RouterOsManager` connection registry (`database.php`-style `default` +
  `connections` config), and a publishable `config/router-os.php`.
- `RouterOsManager` auto-heals a connection that goes dead
  (`Client::isClosed()`) on its *next* access — useful for long-lived
  processes (queue workers, Octane). It deliberately does not retry the
  command that actually failed, to avoid double-executing a non-idempotent
  RouterOS command if it reached the router and only the reply was lost.

## [0.1.1] - 2026-07-10

### Added

- GitHub Actions CI running the test suite on PHP 8.1-8.4 (Ubuntu) and
  PHP 8.4 (Windows), with a status badge in the README.
- This CHANGELOG.

### Changed

- Rewrote the README for public release: badges, a "Why" section, a
  features list, `composer require` instructions, a query builder example,
  and a roadmap.

## [0.1.0] - 2026-07-10

### Added

- RouterOS wire protocol: length-prefix codec and sentence parsing
  (`!re`/`!done`/`!trap`/`!fatal`/`!empty`).
- Real TCP/TLS transport (`StreamTransport`), plus an in-memory test double
  (`FakeTransport`).
- `Connection`: a real tag-multiplexing dispatcher on native PHP 8.1+
  Fibers, so multiple commands/streams can share one connection
  concurrently instead of one command at a time.
- `Channel`: streaming support for `/listen` (event-driven) and
  `=interval=N` (periodic push) RouterOS commands, with `stop()`/`/cancel`.
- `Auth\Authenticator`: modern (`=name=`/`=password=`) and legacy
  (MD5 challenge-response) `/login`.
- `Query`: fluent builder for filters, attributes, and `?#` operations.
- `Client`: public facade (`connect`/`write`/`query`/`listen`/`interval`/`close`).
- `Io\Reactor`: a `stream_select`-based scheduler enabling genuinely
  concurrent socket I/O against a real connection (not just in tests).
- RouterOS protocol quirks handled: `!empty` replies (RouterOS 7.18+),
  unknown/expired tag packets, multi-block `!done` responses (seen on some
  wireless APs), and interval-stream `!done` cycle boundaries.

[Unreleased]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/tecrodrigocastro/router-os-sdk/releases/tag/v0.1.0
