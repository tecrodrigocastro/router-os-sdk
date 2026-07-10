<?php

namespace RouterOS\Sdk\Isp;

use RouterOS\Sdk\Client;

/**
 * PPPoE secrets (/ppp/secret) and active sessions (/ppp/active) — the
 * "provision a customer, kill/monitor their session" surface a typical
 * ISP panel needs. A thin convenience layer over Client::findWhere() /
 * write() — no protocol-level code of its own.
 */
final class PppSecrets
{
    public function __construct(private readonly Client $client)
    {
    }

    public function create(
        string $name,
        string $password,
        string $profile = 'default',
        string $service = 'pppoe',
        ?string $localAddress = null,
        ?string $remoteAddress = null,
    ): void {
        $words = [
            "=name={$name}",
            "=password={$password}",
            "=service={$service}",
            "=profile={$profile}",
        ];

        if (null !== $localAddress) {
            $words[] = "=local-address={$localAddress}";
        }

        if (null !== $remoteAddress) {
            $words[] = "=remote-address={$remoteAddress}";
        }

        $this->client->write('/ppp/secret/add', $words);
    }

    /** @return array<string, string>|null */
    public function find(string $name): ?array
    {
        return $this->client->findOne('/ppp/secret', ['name' => $name]);
    }

    public function remove(string $name): bool
    {
        return $this->client->removeWhere('/ppp/secret', ['name' => $name]) > 0;
    }

    public function setProfile(string $name, string $profile): bool
    {
        return $this->client->setWhere('/ppp/secret', ['name' => $name], ['profile' => $profile]) > 0;
    }

    /**
     * Disable a secret (rejects future dial-in attempts) without deleting
     * it — reversible via enable(). Does not drop an already-active
     * session; pair with kill() if you need that too.
     */
    public function disable(string $name): bool
    {
        return $this->client->setWhere('/ppp/secret', ['name' => $name], ['disabled' => 'yes']) > 0;
    }

    public function enable(string $name): bool
    {
        return $this->client->setWhere('/ppp/secret', ['name' => $name], ['disabled' => 'no']) > 0;
    }

    /**
     * @return array<int, array<string, string>> active sessions, optionally filtered by secret name
     */
    public function activeSessions(?string $name = null): array
    {
        return $this->client->findWhere('/ppp/active', null !== $name ? ['name' => $name] : []);
    }

    public function isOnline(string $name): bool
    {
        return null !== $this->client->findOne('/ppp/active', ['name' => $name]);
    }

    /**
     * Kill active session(s) for a secret — RouterOS/PPP reconnects the
     * client fresh, picking up any new RADIUS/profile attributes (e.g.
     * after a plan change or a suspension).
     *
     * @return int how many sessions were killed
     */
    public function kill(string $name): int
    {
        return $this->client->removeWhere('/ppp/active', ['name' => $name]);
    }
}
