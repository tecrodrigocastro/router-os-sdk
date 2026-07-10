<?php

namespace RouterOS\Sdk\Isp;

use RouterOS\Sdk\Client;

/**
 * One named RouterOS firewall address-list (/ip/firewall/address-list) —
 * the usual mechanism for blocking delinquent customers ("morosos") via a
 * firewall rule that matches the list, without touching the rule itself
 * per customer.
 */
final class AddressList
{
    public function __construct(
        private readonly Client $client,
        private readonly string $listName,
    ) {
    }

    /**
     * Idempotent: does nothing if $address is already in the list, so
     * calling this repeatedly (e.g. a retried job) never creates duplicate
     * entries.
     *
     * @return bool true if an entry was added, false if it was already blocked
     */
    public function block(string $address, ?string $comment = null): bool
    {
        if ($this->isBlocked($address)) {
            return false;
        }

        $words = [
            "=list={$this->listName}",
            "=address={$address}",
        ];

        if (null !== $comment) {
            $words[] = "=comment={$comment}";
        }

        $this->client->write('/ip/firewall/address-list/add', $words);

        return true;
    }

    public function unblock(string $address): bool
    {
        return $this->client->removeWhere('/ip/firewall/address-list', [
            'list'    => $this->listName,
            'address' => $address,
        ]) > 0;
    }

    public function isBlocked(string $address): bool
    {
        return null !== $this->client->findOne('/ip/firewall/address-list', [
            'list'    => $this->listName,
            'address' => $address,
        ]);
    }

    /** @return array<int, array<string, string>> every entry currently in this list */
    public function all(): array
    {
        return $this->client->findWhere('/ip/firewall/address-list', ['list' => $this->listName]);
    }
}
