<?php

namespace RouterOS\Sdk\Integrations\Laravel;

use RouterOS\Sdk\Client;
use RouterOS\Sdk\Exceptions\ConfigException;

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
 */
final class RouterOsManager
{
    /** @var array<string, Client> */
    private array $connections = [];

    /** @var callable(array<string, mixed>): Client */
    private $connector;

    /**
     * @param array<string, array<string, mixed>> $connectionsConfig keyed by connection name
     * @param (callable(array<string, mixed>): Client)|null $connector override for tests —
     *        defaults to Client::connect($config) against a real socket
     */
    public function __construct(
        private readonly array $connectionsConfig,
        private readonly string $default,
        ?callable $connector = null,
    ) {
        $this->connector = $connector ?? static fn (array $config): Client => Client::connect($config);
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

        return $this->connections[$name] = ($this->connector)($this->connectionsConfig[$name]);
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
     * Forwards to the default connection, mirroring how Laravel's own
     * facades (DB::table(), Cache::get(), ...) proxy to a default
     * connection/store without an explicit connection() call.
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->connection()->{$method}(...$arguments);
    }
}
