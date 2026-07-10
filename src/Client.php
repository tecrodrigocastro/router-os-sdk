<?php

namespace RouterOS\Sdk;

use RouterOS\Sdk\Auth\Authenticator;
use RouterOS\Sdk\Io\Reactor;

/**
 * Public entry point: connect, authenticate, and issue commands/streams.
 *
 * This is intentionally a thin wrapper around Connection — Connection owns
 * the actual tag-multiplexing dispatcher; Client just bundles
 * connect+login and gives the primitives their public names
 * (write/listen/interval), mirroring MikroDash's ROS.write()/ROS.stream()
 * naming so the mental model carries over directly.
 */
final class Client
{
    private function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed>|Config $config see Config for accepted keys
     *                                              (host, user, pass, port, tls, legacy, ...)
     * @param Reactor|null $reactor pass a shared Reactor to get genuinely
     *        concurrent write()/listen()/interval() calls against this
     *        connection — see Reactor's docblock for how it's driven
     */
    public static function connect(array|Config $config, ?Reactor $reactor = null): self
    {
        $config     = is_array($config) ? new Config($config) : $config;
        $connection = Connection::connect($config, $reactor);

        Authenticator::login($connection, $config);

        return new self($connection);
    }

    /**
     * Wrap an already-connected (and, if needed, already-authenticated)
     * Connection directly — useful for tests and for advanced callers that
     * built their Connection some other way.
     */
    public static function fromConnection(Connection $connection): self
    {
        return new self($connection);
    }

    /**
     * One-shot command. Blocks (cooperatively — see Connection) until
     * RouterOS answers, then returns the rows.
     *
     * @param string[] $words "=key=value" / "?key=value" style words, or build them with Query
     * @return array<int, array<string, string>>
     */
    public function write(string $endpoint, array $words = []): array
    {
        return $this->connection->write($endpoint, $words);
    }

    public function query(Query $query): array
    {
        return $this->connection->executeQuery($query);
    }

    /**
     * Event-driven stream (e.g. "/ip/arp/listen"). Returns immediately;
     * consume the returned Channel via onData() or wait().
     *
     * @param string[] $words
     */
    public function listen(string $endpoint, array $words = []): Channel
    {
        return $this->connection->listen($endpoint, $words);
    }

    /**
     * "=interval=N" push stream for print commands with no /listen variant
     * (e.g. "/system/resource/print"). Each cycle is delivered as one payload.
     *
     * @param string[] $words
     */
    public function interval(string $endpoint, int $seconds, array $words = []): Channel
    {
        return $this->connection->interval($endpoint, $seconds, $words);
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function isClosed(): bool
    {
        return $this->connection->isClosed();
    }
}
