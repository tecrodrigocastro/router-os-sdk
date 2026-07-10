<?php

namespace RouterOS\Sdk;

use RouterOS\Sdk\Exceptions\QueryException;

/**
 * Fluent builder for a single RouterOS API sentence's attribute words
 * (everything after the endpoint: "=key=value", "?key=value" filters,
 * "?#operations", ".tag=N").
 *
 * Ported from evilfreelancer/routeros-api-php's Query.php. The endpoint is
 * kept separate from the word list here (rather than prepended into
 * getQuery() as that library does) because Connection::write()/listen()
 * take the endpoint as their own argument.
 */
final class Query
{
    private const AVAILABLE_OPERATORS = ['-', '=', '>', '<'];

    /** @var string[] */
    private array $attributes = [];
    private ?string $operations = null;
    private ?string $tag = null;

    /**
     * @param string[] $attributes pre-built "=key=value" style words
     */
    public function __construct(private readonly string $endpoint, array $attributes = [])
    {
        if ('' === $endpoint) {
            throw new QueryException('Endpoint of query cannot be empty');
        }

        $this->attributes = $attributes;
    }

    /**
     * @param bool|string|int|null $value
     * @param string|null          $operator one of '-', '=', '>', '<'
     */
    public function where(string $key, string|int|bool|null $operator = null, string|int|bool|null $value = null): self
    {
        if (null !== $operator && null === $value) {
            // Two positional args given: ($key, $value) — shorthand for equality.
            $value    = $operator;
            $operator = null;
        }

        // Operator sits *between* the '?' and the key on the wire
        // (e.g. the word for "mtu greater-than 1000" is '?' + '>' + 'mtu=1000'),
        // never before the '?'.
        return $this->word('?', $key, is_string($operator) ? $operator : null, $value);
    }

    public function equal(string $key, string|int|bool|null $value = null): self
    {
        return $this->word('=', $key, null, $value);
    }

    private function word(string $prefix, string $key, ?string $operator, string|int|bool|null $value): self
    {
        if (null !== $operator && !in_array($operator, self::AVAILABLE_OPERATORS, true)) {
            throw new QueryException(
                'Operator "' . $operator . '" is not in the allowed list [' . implode(',', self::AVAILABLE_OPERATORS) . ']'
            );
        }

        $word = $prefix . ($operator ?? '') . $key;

        if (null !== $value) {
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $word .= '=' . $value;
        }

        $this->attributes[] = $word;

        return $this;
    }

    public function operations(string $operations): self
    {
        $this->operations = '?#' . $operations;

        return $this;
    }

    public function tag(string $name): self
    {
        $this->tag = $name;

        return $this;
    }

    public function add(string $word): self
    {
        $this->attributes[] = $word;

        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getTag(): ?string
    {
        return $this->tag;
    }

    /**
     * @return string[] Attribute words for this sentence, NOT including the
     *                   endpoint (Connection sends that as the first word).
     */
    public function toWords(): array
    {
        $words = $this->attributes;

        if (null !== $this->operations) {
            $words[] = $this->operations;
        }

        if (null !== $this->tag) {
            $words[] = '.tag=' . $this->tag;
        }

        return $words;
    }
}
