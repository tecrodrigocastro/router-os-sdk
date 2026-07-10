<?php

namespace RouterOS\Sdk\Integrations\Laravel;

use RouterOS\Sdk\Client;
use RouterOS\Sdk\Exceptions\ConfigException;
use RouterOS\Sdk\Exceptions\RecoverableException;
use RouterOS\Sdk\Exceptions\TransportException;

/**
 * Registry of named RouterOS connections, resolved lazily and cached —
 * the Laravel equivalent of Illuminate\Database\DatabaseManager, adapted
 * to RouterOS\Sdk\Client.
 *
 * Reconnect strategy is "auto-heal on next access," not a silent mid-call
 * retry: StreamTransport already flips isClosed() to true on any read/write
 * failure, so connection() simply discards a cached Client that reports
 * isClosed() and builds a fresh one. The call that actually failed still
 * throws normally — retrying a write automatically would risk
 * double-executing a non-idempotent RouterOS command (e.g. /ip/address/add)
 * if the command reached the router and only the reply was lost. That
 * decision is left to the caller, who knows whether their specific command
 * is safe to retry; the manager only guarantees the *next* connection()
 * call gets a working connection instead of a known-dead one.
 *
 * Reconnect cooldown: if the router is genuinely unreachable, every
 * connection() call would otherwise pay a full connect_timeout (10s
 * default) trying and failing again — expensive under sustained queue
 * throughput. After a RecoverableException from the connector, further
 * attempts for that connection name fail immediately (no socket touched)
 * until $reconnectCooldownSeconds have passed.
 */
final class RouterOsManager
{
    /** @var array<string, Client> */
    private array $connections = [];

    /** @var array<string, float> connection name => microtime() of last failure */
    private array $lastFailureAt = [];

    /** @var callable(array<string, mixed>): Client */
    private $connector;

    /** @var callable(): float */
    private $now;

    /**
     * @param array<string, array<string, mixed>> $connectionsConfig keyed by connection name
     * @param (callable(array<string, mixed>): Client)|null $connector override for tests —
     *        defaults to Client::connect($config) against a real socket
     * @param (callable(): float)|null $now override for tests — defaults to microtime(true)
     */
    public function __construct(
        private array $connectionsConfig,
        private readonly string $default,
        ?callable $connector = null,
        private readonly float $reconnectCooldownSeconds = 5.0,
        ?callable $now = null,
    ) {
        $this->connector = $connector ?? static fn (array $config): Client => Client::connect($config);
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    public function connection(?string $name = null): Client
    {
        $name = $name ?? $this->default;

        if (isset($this->connections[$name]) && !$this->connections[$name]->isClosed()) {
            return $this->connections[$name];
        }

        if (!isset($this->connectionsConfig[$name])) {
            throw new ConfigException("RouterOS connection \"{$name}\" is not configured (see config/router-os.php)");
        }

        if (isset($this->lastFailureAt[$name])) {
            $elapsed = ($this->now)() - $this->lastFailureAt[$name];
            if ($elapsed < $this->reconnectCooldownSeconds) {
                $wait = round($this->reconnectCooldownSeconds - $elapsed, 1);
                throw new TransportException(
                    "RouterOS connection \"{$name}\" failed recently — skipping reconnect attempt for another {$wait}s"
                );
            }
        }

        try {
            $client = ($this->connector)($this->connectionsConfig[$name]);
        } catch (RecoverableException $e) {
            $this->lastFailureAt[$name] = ($this->now)();

            throw $e;
        }

        unset($this->lastFailureAt[$name]);

        return $this->connections[$name] = $client;
    }

    /**
     * Force the next connection($name) call to build a fresh Client, even
     * if the cached one doesn't (yet) report isClosed() — an explicit
     * escape hatch for callers that detect a dead connection some other
     * way (e.g. a Channel that stopped delivering data).
     */
    public function forgetConnection(?string $name = null): void
    {
        unset($this->connections[$name ?? $this->default]);
    }

    /**
     * Register (or overwrite) a connection's config at runtime — for
     * connections that aren't known statically at boot (e.g. one per row
     * in a database table), unlike config/router-os.php's fixed list.
     * Overwriting a name that already has a cached Client does NOT close
     * it; call forgetConnectionConfig() first if you need the new config
     * to take effect on the very next connection() call.
     *
     * @param array<string, mixed> $config
     */
    public function registerConnection(string $name, array $config): void
    {
        $this->connectionsConfig[$name] = $config;
    }

    public function hasConnection(string $name): bool
    {
        return isset($this->connectionsConfig[$name]);
    }

    /**
     * Fully deregister a connection: drops its config, any cached Client,
     * and any cooldown state — the next connection($name) call throws
     * ConfigException until it's registered again. Use when a router is
     * deleted/reassigned, not just when it's temporarily unreachable (for
     * that, the cooldown already handles it — see the class docblock).
     */
    public function forgetConnectionConfig(string $name): void
    {
        unset($this->connectionsConfig[$name], $this->connections[$name], $this->lastFailureAt[$name]);
    }

    /**
     * Forwards to the default connection, mirroring how Laravel's own
     * facades (DB::table(), Cache::get(), ...) proxy to a default
     * connection/store without an explicit connection() call.
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->connection()->{$method}(...$arguments);
    }
}
