# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/tecrodrigocastro/router-os-sdk/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/tecrodrigocastro/router-os-sdk/releases/tag/v0.1.0
