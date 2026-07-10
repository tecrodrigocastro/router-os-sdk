<?php

namespace RouterOS\Sdk\Isp;

use RouterOS\Sdk\Client;

/**
 * Queue tree (/queue/tree) — hierarchical bandwidth shaping (parent queues
 * + packet-mark matched children), for setups more advanced than
 * SimpleQueue's flat per-target limits.
 */
final class QueueTree
{
    public function __construct(private readonly Client $client)
    {
    }

    public function create(
        string $name,
        string $parent,
        ?string $packetMark = null,
        ?string $maxLimit = null,
    ): void {
        $words = [
            "=name={$name}",
            "=parent={$parent}",
        ];

        if (null !== $packetMark) {
            $words[] = "=packet-mark={$packetMark}";
        }

        if (null !== $maxLimit) {
            $words[] = "=max-limit={$maxLimit}";
        }

        $this->client->write('/queue/tree/add', $words);
    }

    /** @return array<string, string>|null */
    public function find(string $name): ?array
    {
        return $this->client->findOne('/queue/tree', ['name' => $name]);
    }

    public function remove(string $name): bool
    {
        return $this->client->removeWhere('/queue/tree', ['name' => $name]) > 0;
    }

    public function setMaxLimit(string $name, string $maxLimit): bool
    {
        return $this->client->setWhere('/queue/tree', ['name' => $name], ['max-limit' => $maxLimit]) > 0;
    }

    public function disable(string $name): bool
    {
        return $this->client->setWhere('/queue/tree', ['name' => $name], ['disabled' => 'yes']) > 0;
    }

    public function enable(string $name): bool
    {
        return $this->client->setWhere('/queue/tree', ['name' => $name], ['disabled' => 'no']) > 0;
    }
}
