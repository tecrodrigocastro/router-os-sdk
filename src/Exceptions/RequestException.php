<?php

namespace RouterOS\Sdk\Exceptions;

use RuntimeException;

/**
 * RouterOS answered a command with !trap — the command itself was rejected
 * (bad syntax, permission denied, "already have such address", etc.), as
 * opposed to a transport/protocol failure.
 */
class RequestException extends RuntimeException
{
    /** @param array<string, string> $trapAttributes */
    public function __construct(private readonly array $trapAttributes, ?string $message = null)
    {
        parent::__construct($message ?? ($trapAttributes['message'] ?? 'RouterOS command failed (!trap)'));
    }

    /** @return array<string, string> */
    public function trapAttributes(): array
    {
        return $this->trapAttributes;
    }
}
