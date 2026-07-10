<?php

namespace RouterOS\Sdk\Isp;

/**
 * Outcome of Customer::suspend()/activate(): each requested action
 * (address-list, PPP secret, queue) runs independently — one failing
 * doesn't stop the others, matching a "settle all, report each" contract
 * rather than an all-or-nothing transaction (RouterOS has no such thing
 * across unrelated resource types anyway).
 */
final class CustomerActionResult
{
    /**
     * @param string[] $succeeded action names that completed, e.g. ['address_list', 'ppp_disabled']
     * @param array<string, string> $failed action name => exception message
     */
    public function __construct(
        public readonly array $succeeded,
        public readonly array $failed,
    ) {
    }

    public function isFullSuccess(): bool
    {
        return [] === $this->failed;
    }

    public function isTotalFailure(): bool
    {
        return [] === $this->succeeded && [] !== $this->failed;
    }
}
