<?php

namespace RouterOS\Sdk\Isp;

use RouterOS\Sdk\Client;

/**
 * PPP profiles (/ppp/profile) — the rate-limit/address templates PPPoE
 * secrets reference (see PppSecrets::create()'s $profile argument).
 */
final class PppProfiles
{
    public function __construct(private readonly Client $client)
    {
    }

    public function create(
        string $name,
        ?string $rateLimit = null,
        ?string $localAddress = null,
        ?string $remoteAddress = null,
    ): void {
        $words = ["=name={$name}"];

        if (null !== $rateLimit) {
            $words[] = "=rate-limit={$rateLimit}";
        }

        if (null !== $localAddress) {
            $words[] = "=local-address={$localAddress}";
        }

        if (null !== $remoteAddress) {
            $words[] = "=remote-address={$remoteAddress}";
        }

        $this->client->write('/ppp/profile/add', $words);
    }

    /** @return array<string, string>|null */
    public function find(string $name): ?array
    {
        return $this->client->findOne('/ppp/profile', ['name' => $name]);
    }

    public function remove(string $name): bool
    {
        return $this->client->removeWhere('/ppp/profile', ['name' => $name]) > 0;
    }

    public function setRateLimit(string $name, string $rateLimit): bool
    {
        return $this->client->setWhere('/ppp/profile', ['name' => $name], ['rate-limit' => $rateLimit]) > 0;
    }

    /** @return array<int, array<string, string>> every profile on the router */
    public function all(): array
    {
        return $this->client->findWhere('/ppp/profile');
    }
}
