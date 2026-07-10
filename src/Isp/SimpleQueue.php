<?php

namespace RouterOS\Sdk\Isp;

use RouterOS\Sdk\Client;

/**
 * Simple queues (/queue/simple) — per-customer bandwidth shaping.
 */
final class SimpleQueue
{
    public function __construct(private readonly Client $client)
    {
    }

    public function create(string $name, string $target, string $maxLimit): void
    {
        $this->client->write('/queue/simple/add', [
            "=name={$name}",
            "=target={$target}",
            "=max-limit={$maxLimit}",
        ]);
    }

    /** @return array<string, string>|null */
    public function find(string $name): ?array
    {
        return $this->client->findOne('/queue/simple', ['name' => $name]);
    }

    public function remove(string $name): bool
    {
        return $this->client->removeWhere('/queue/simple', ['name' => $name]) > 0;
    }

    public function setMaxLimit(string $name, string $maxLimit): bool
    {
        return $this->client->setWhere('/queue/simple', ['name' => $name], ['max-limit' => $maxLimit]) > 0;
    }
}
