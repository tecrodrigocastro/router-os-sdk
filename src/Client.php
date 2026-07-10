<?php

namespace RouterOS\Sdk;

use RouterOS\Sdk\Auth\Authenticator;
use RouterOS\Sdk\Io\Reactor;
use RouterOS\Sdk\Isp\AddressList;
use RouterOS\Sdk\Isp\Customer;
use RouterOS\Sdk\Isp\Firewall;
use RouterOS\Sdk\Isp\PppProfiles;
use RouterOS\Sdk\Isp\PppSecrets;
use RouterOS\Sdk\Isp\QueueTree;
use RouterOS\Sdk\Isp\SimpleQueue;
use RouterOS\Sdk\Vpn\WireGuard;

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
    private ?PppSecrets $pppSecrets = null;

    private ?PppProfiles $pppProfiles = null;

    private ?SimpleQueue $simpleQueue = null;

    private ?QueueTree $queueTree = null;

    private ?Firewall $firewall = null;

    /** @var array<string, AddressList> */
    private array $addressLists = [];

    /** @var array<string, Customer> */
    private array $customers = [];

    /** @var array<string, WireGuard> */
    private array $wireGuards = [];

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

    /**
     * "Find" half of RouterOS's near-universal add/print/set/remove
     * resource convention: a /print with one equality filter per entry in
     * $filters (e.g. ['name' => 'joao'] -> "?name=joao"), built via Query
     * rather than reimplementing word-building.
     *
     * @param string $resource e.g. "/ppp/secret" (no trailing /print)
     * @param array<string, string> $filters
     * @return array<int, array<string, string>>
     */
    public function findWhere(string $resource, array $filters = []): array
    {
        $query = new Query(rtrim($resource, '/') . '/print');
        foreach ($filters as $key => $value) {
            $query->where((string) $key, (string) $value);
        }

        return $this->query($query);
    }

    /**
     * @param array<string, string> $filters
     * @return array<string, string>|null the first matching row, or null if none
     */
    public function findOne(string $resource, array $filters = []): ?array
    {
        return $this->findWhere($resource, $filters)[0] ?? null;
    }

    /**
     * Find rows matching $filters and /remove each one by its .id.
     *
     * @param array<string, string> $filters
     * @return int how many rows were removed
     */
    public function removeWhere(string $resource, array $filters): int
    {
        $resource = rtrim($resource, '/');
        $removed  = 0;

        foreach ($this->findWhere($resource, $filters) as $row) {
            if (isset($row['.id'])) {
                $this->write($resource . '/remove', ['=.id=' . $row['.id']]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Find rows matching $filters and /set each one with $updates.
     *
     * @param array<string, string> $filters
     * @param array<string, string> $updates
     * @return int how many rows were updated
     */
    public function setWhere(string $resource, array $filters, array $updates): int
    {
        $resource = rtrim($resource, '/');
        $updated  = 0;

        foreach ($this->findWhere($resource, $filters) as $row) {
            if (!isset($row['.id'])) {
                continue;
            }

            $words = ['=.id=' . $row['.id']];
            foreach ($updates as $key => $value) {
                $words[] = "={$key}={$value}";
            }

            $this->write($resource . '/set', $words);
            $updated++;
        }

        return $updated;
    }

    /** PPPoE secrets/active sessions (see RouterOS\Sdk\Isp\PppSecrets) */
    public function pppSecrets(): PppSecrets
    {
        return $this->pppSecrets ??= new PppSecrets($this);
    }

    /** One named firewall address-list, e.g. "morosos" (see RouterOS\Sdk\Isp\AddressList) */
    public function addressList(string $listName): AddressList
    {
        return $this->addressLists[$listName] ??= new AddressList($this, $listName);
    }

    /** Simple queues / bandwidth shaping (see RouterOS\Sdk\Isp\SimpleQueue) */
    public function simpleQueue(): SimpleQueue
    {
        return $this->simpleQueue ??= new SimpleQueue($this);
    }

    /** PPP profiles / rate-limit templates (see RouterOS\Sdk\Isp\PppProfiles) */
    public function pppProfiles(): PppProfiles
    {
        return $this->pppProfiles ??= new PppProfiles($this);
    }

    /** Hierarchical bandwidth shaping (see RouterOS\Sdk\Isp\QueueTree) */
    public function queueTree(): QueueTree
    {
        return $this->queueTree ??= new QueueTree($this);
    }

    /** Idempotent firewall rule installation (see RouterOS\Sdk\Isp\Firewall) */
    public function firewall(): Firewall
    {
        return $this->firewall ??= new Firewall($this);
    }

    /**
     * Unified suspend/activate for one customer (see RouterOS\Sdk\Isp\Customer).
     *
     * @param string $blockListName the address-list this customer's suspend()/activate() uses
     */
    public function customer(string $identifier, string $blockListName = 'suspended'): Customer
    {
        return $this->customers[$identifier] ??= new Customer($this, $identifier, $blockListName);
    }

    /** One named WireGuard interface (see RouterOS\Sdk\Vpn\WireGuard) */
    public function wireGuard(string $interfaceName): WireGuard
    {
        return $this->wireGuards[$interfaceName] ??= new WireGuard($this, $interfaceName);
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
