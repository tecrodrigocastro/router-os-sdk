<?php

namespace RouterOS\Sdk\Isp;

use RouterOS\Sdk\Client;
use Throwable;

/**
 * Unified suspend/activate for a customer touching several RouterOS
 * resources at once (firewall address-list, PPP secret, bandwidth queue).
 * Each requested action runs independently in its own try/catch: one
 * failing (e.g. the queue doesn't exist) doesn't prevent the others from
 * running, and the caller gets back exactly which actions succeeded and
 * which failed — the same "settle all independently" contract a
 * Promise.allSettled-based implementation would give, without needing any
 * concurrency machinery for three small sequential commands on one
 * connection.
 */
final class Customer
{
    public function __construct(
        private readonly Client $client,
        private readonly string $identifier,
        private readonly string $blockListName = 'suspended',
    ) {
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function suspend(?string $address = null, ?string $pppUser = null, ?string $queueName = null): CustomerActionResult
    {
        $succeeded = [];
        $failed    = [];

        if (null !== $address) {
            try {
                $this->client->addressList($this->blockListName)->block($address, comment: $this->identifier);
                $succeeded[] = 'address_list';
            } catch (Throwable $e) {
                $failed['address_list'] = $e->getMessage();
            }
        }

        if (null !== $pppUser) {
            try {
                $this->client->pppSecrets()->disable($pppUser);
                $this->client->pppSecrets()->kill($pppUser);
                $succeeded[] = 'ppp_disabled';
            } catch (Throwable $e) {
                $failed['ppp_disabled'] = $e->getMessage();
            }
        }

        if (null !== $queueName) {
            try {
                $this->client->simpleQueue()->disable($queueName);
                $succeeded[] = 'queue_disabled';
            } catch (Throwable $e) {
                $failed['queue_disabled'] = $e->getMessage();
            }
        }

        return new CustomerActionResult($succeeded, $failed);
    }

    public function activate(?string $address = null, ?string $pppUser = null, ?string $queueName = null): CustomerActionResult
    {
        $succeeded = [];
        $failed    = [];

        if (null !== $address) {
            try {
                $this->client->addressList($this->blockListName)->unblock($address);
                $succeeded[] = 'address_list';
            } catch (Throwable $e) {
                $failed['address_list'] = $e->getMessage();
            }
        }

        if (null !== $pppUser) {
            try {
                $this->client->pppSecrets()->enable($pppUser);
                $succeeded[] = 'ppp_enabled';
            } catch (Throwable $e) {
                $failed['ppp_enabled'] = $e->getMessage();
            }
        }

        if (null !== $queueName) {
            try {
                $this->client->simpleQueue()->enable($queueName);
                $succeeded[] = 'queue_enabled';
            } catch (Throwable $e) {
                $failed['queue_enabled'] = $e->getMessage();
            }
        }

        return new CustomerActionResult($succeeded, $failed);
    }
}
