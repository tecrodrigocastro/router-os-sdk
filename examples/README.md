# Examples

Runnable scripts against a real RouterOS device. Credentials always come
from environment variables (never hardcoded), so these are safe to commit
and safe to copy as a starting point.

```bash
ROUTEROS_HOST=192.168.88.1 ROUTEROS_USER=admin ROUTEROS_PASS=secret php examples/basic-usage.php
```

`ROUTEROS_TLS=true` enables TLS (defaults to `false`, plain API on 8728).

| Script | What it shows |
|---|---|
| `basic-usage.php` | Connect, run a one-shot command, close. |
| `streaming-listen.php` | Event-driven stream (`/ip/arp/listen`) — one payload per change. |
| `streaming-interval.php` | `=interval=N` push stream (`/system/resource/print` every 2s). |
| `concurrent-reactor.php` | The core claim proven live: `write()` once/sec **while** a `listen()` stream stays open, both on one connection via `Io\Reactor`. |
| `managed-client.php` | Long-running supervisor (`ManagedClient`): reconnects with exponential backoff if the connection dies — try unplugging the router's cable while this runs. |
| `laravel-usage.php` | Not runnable standalone — illustrates the Facade/DI patterns for a real Laravel app (see the main README's Laravel section for setup). |

All of these except `laravel-usage.php` require an actual reachable
RouterOS device; `concurrent-reactor.php` and `managed-client.php` are the
most interesting ones to run.
