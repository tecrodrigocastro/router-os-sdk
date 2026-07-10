<?php

namespace RouterOS\Sdk\Protocol;

/**
 * One parsed RouterOS API sentence: a type word (!re, !done, !trap, !fatal,
 * !empty) followed by zero or more "=key=value" / ".tag=value" words,
 * terminated by a zero-length word on the wire (already consumed by the
 * time this object exists).
 */
final class Sentence
{
    public const TYPE_RE    = '!re';
    public const TYPE_DONE  = '!done';
    public const TYPE_TRAP  = '!trap';
    public const TYPE_FATAL = '!fatal';
    public const TYPE_EMPTY = '!empty';

    /**
     * @param array<string, string> $attributes
     */
    public function __construct(
        public readonly string $type,
        public readonly array $attributes = [],
    ) {
    }

    public function tag(): ?string
    {
        return $this->attributes['.tag'] ?? null;
    }

    public function isData(): bool
    {
        return self::TYPE_RE === $this->type;
    }

    public function isTrap(): bool
    {
        return self::TYPE_TRAP === $this->type;
    }

    public function isFatal(): bool
    {
        return self::TYPE_FATAL === $this->type;
    }

    /**
     * True for any sentence that ends a command's response.
     *
     * RouterOS 7.18+ quirk: `!empty` is sent instead of `!done` when a
     * command matched zero rows. Treat it identically to `!done` — an
     * empty, successful completion — rather than as an unknown reply.
     */
    public function isTerminal(): bool
    {
        return match ($this->type) {
            self::TYPE_DONE, self::TYPE_FATAL, self::TYPE_EMPTY => true,
            default => false,
        };
    }
}
