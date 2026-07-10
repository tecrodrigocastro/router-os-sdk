<?php

namespace RouterOS\Sdk;

use RouterOS\Sdk\Exceptions\RecoverableException;
use RouterOS\Sdk\Io\Reactor;

/**
 * A supervisor for long-running processes (a daemon script, an Artisan
 * command that never exits) — the PHP equivalent of MikroDash's Node `ROS`
 * class connectLoop(): connect, hand a working Client to the caller's setup
 * code, and if the connection dies, reconnect with exponential backoff and
 * hand over a fresh one again.
 *
 * Deliberately does NOT try to be a generic scheduler on top of what it
 * connects: onConnected() callbacks own their own work. The common shape is
 * a blocking consumer loop:
 *
 *   $managed->onConnected(function (Client $client) {
 *       $arp = $client->listen('/ip/arp/listen');
 *       while (true) {
 *           $row = $arp->wait(); // throws when the connection dies
 *           ...
 *       }
 *   });
 *   $managed->run();
 *
 * For concurrent work (write() + listen() at once), pass a Reactor to the
 * constructor and drive your own Fiber + Reactor::tick() loop inside the
 * callback — the same pattern as examples/concurrent-reactor.php. run()'s
 * job is only to notice the cycle ended (the callback returned, or threw a
 * RecoverableException) and reconnect; it does not drive the Reactor itself.
 */
final class ManagedClient
{
    private bool $stopping = false;

    /** @var array<callable(Client, self): void> */
    private array $onConnectedCallbacks = [];

    /** @var array<callable(): void> */
    private array $onDisconnectedCallbacks = [];

    /** @var callable(array<string, mixed>|Config, ?Reactor): Client */
    private $connector;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param array<string, mixed>|Config $config see Config for accepted keys
     * @param (callable(array<string, mixed>|Config, ?Reactor): Client)|null $connector
     *        override for tests — defaults to Client::connect($config, $reactor)
     * @param (callable(int): void)|null $sleeper override for tests — receives the
     *        backoff in milliseconds; defaults to a real usleep()
     */
    public function __construct(
        private readonly array|Config $config,
        private readonly ?Reactor $reactor = null,
        private readonly int $initialBackoffMs = 2000,
        private readonly int $maxBackoffMs = 30000,
        ?callable $connector = null,
        ?callable $sleeper = null,
    ) {
        $this->connector = $connector
            ?? static fn (array|Config $config, ?Reactor $reactor): Client => Client::connect($config, $reactor);
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * @param callable(Client, self): void $callback called every time a
     *        (re)connection succeeds — this is where you set up your work.
     */
    public function onConnected(callable $callback): self
    {
        $this->onConnectedCallbacks[] = $callback;

        return $this;
    }

    /**
     * @param callable(): void $callback called after a connection cycle ends,
     *        before the backoff sleep — a chance to clean up (e.g. drop
     *        references to Channels from the dead connection).
     */
    public function onDisconnected(callable $callback): self
    {
        $this->onDisconnectedCallbacks[] = $callback;

        return $this;
    }

    /**
     * Ask the loop to stop after the current cycle. Safe to call from
     * inside an onConnected callback — check isStopping() in your own loop
     * condition to end it gracefully instead of only being cut off by a
     * connection failure.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    public function isStopping(): bool
    {
        return $this->stopping;
    }

    /**
     * Blocks until stop() is called. Connects with backoff on failure; on
     * success, runs the onConnected callbacks, then waits for that
     * connection cycle to end (the callbacks returned, or one threw a
     * RecoverableException) before running onDisconnected callbacks,
     * backing off, and reconnecting.
     */
    public function run(): void
    {
        $backoffMs = $this->initialBackoffMs;

        while (!$this->stopping) {
            try {
                $client = ($this->connector)($this->config, $this->reactor);
            } catch (RecoverableException $e) {
                if ($this->stopping) {
                    break;
                }

                ($this->sleeper)($backoffMs);
                $backoffMs = min($backoffMs * 2, $this->maxBackoffMs);

                continue;
            }

            $backoffMs = $this->initialBackoffMs;

            try {
                foreach ($this->onConnectedCallbacks as $callback) {
                    $callback($client, $this);
                }
            } catch (RecoverableException $e) {
                // connection died while a callback was running — fall
                // through to teardown/backoff/reconnect below.
            }

            if (!$client->isClosed()) {
                $client->close();
            }

            foreach ($this->onDisconnectedCallbacks as $callback) {
                $callback();
            }

            if ($this->stopping) {
                break;
            }

            ($this->sleeper)($backoffMs);
            $backoffMs = min($backoffMs * 2, $this->maxBackoffMs);
        }
    }
}
