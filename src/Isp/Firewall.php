<?php

namespace RouterOS\Sdk\Isp;

use RouterOS\Sdk\Client;

/**
 * Idempotent firewall rule installation — the "make sure this rule exists,
 * don't duplicate it on a retried job" pattern, keyed by comment (RouterOS
 * has no other stable natural key across filter/nat/mangle rules).
 */
final class Firewall
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Create a rule under $table ("filter", "nat", or "mangle") if no rule
     * with $comment already exists there.
     *
     * @param array<string, string> $words all other "=key=value" fields
     *        (chain, action, src-address-list, protocol, dst-port, ...)
     * @return bool true if a rule was created, false if one already existed
     */
    public function ensureRule(string $table, array $words, string $comment): bool
    {
        if ($this->ruleExists($table, $comment)) {
            return false;
        }

        $wireWords = [];
        foreach ($words as $key => $value) {
            $wireWords[] = "={$key}={$value}";
        }
        $wireWords[] = "=comment={$comment}";

        $this->client->write("/ip/firewall/{$table}/add", $wireWords);

        return true;
    }

    public function ruleExists(string $table, string $comment): bool
    {
        return null !== $this->client->findOne("/ip/firewall/{$table}", ['comment' => $comment]);
    }

    public function removeRule(string $table, string $comment): bool
    {
        return $this->client->removeWhere("/ip/firewall/{$table}", ['comment' => $comment]) > 0;
    }
}
