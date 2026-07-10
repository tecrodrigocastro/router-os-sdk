<?php

namespace RouterOS\Sdk;

use LogicException;
use RouterOS\Sdk\Exceptions\RequestException;
use RouterOS\Sdk\Protocol\Sentence;

/**
 * A long-lived stream: either a RouterOS `/listen` (event-driven — one !re
 * per change, tag stays open until cancelled) or an `=interval=N` print
 * (RouterOS pushes a fresh !re...!done "cycle" every N seconds over the
 * same open tag).
 *
 * The distinction matters for exactly one thing, mirroring patch #5 in
 * MikroDash's patch-routeros.js: for `=interval=N` streams, `!done` is only
 * a cycle boundary — the tag must stay registered so RouterOS can keep
 * pushing. For `/listen` streams, RouterOS only ever sends a genuine
 * `!done` when the stream has actually ended (e.g. after /cancel), so
 * `!done` there does mean "close".
 */
final class Channel implements TagConsumer
{
    public const MODE_LISTEN   = 'listen';
    public const MODE_INTERVAL = 'interval';

    /** @var array<int, array<string, string>> current interval cycle's accumulated rows */
    private array $cycleBuffer = [];

    /** @var array<int, array<int, array<string, string>>> buffered payloads awaiting a consumer */
    private array $pending = [];

    private bool $closed = false;
    private ?RequestException $error = null;

    /** @var callable(array<int, array<string, string>>): void|null */
    private $onData;

    private ?Connection $connection = null;
    private ?string $tag = null;

    public function __construct(private readonly string $mode)
    {
    }

    /** @internal set by Connection right after registering the channel */
    public function attach(Connection $connection, string $tag): void
    {
        $this->connection = $connection;
        $this->tag        = $tag;
    }

    /**
     * @param callable(array<int, array<string, string>>): void $callback
     *        Called once per event (listen mode: one row per call) or once
     *        per cycle (interval mode: all of that cycle's rows at once).
     */
    public function onData(callable $callback): void
    {
        $this->onData = $callback;

        // Flush anything already buffered before a consumer attached.
        while ([] !== $this->pending) {
            $callback(array_shift($this->pending));
        }
    }

    public function deliver(Sentence $sentence): bool
    {
        if ($sentence->isData()) {
            $row = $sentence->attributes;
            unset($row['.tag']);

            if (self::MODE_LISTEN === $this->mode) {
                $this->emit([$row]);
            } else {
                $this->cycleBuffer[] = $row;
            }

            return false;
        }

        if ($sentence->isTrap()) {
            $this->error = new RequestException($sentence->attributes);

            return false; // RouterOS still sends a !done to close out the trap
        }

        // Terminal sentence (!done / !empty / !fatal).
        if (null !== $this->error || $sentence->isFatal()) {
            $this->closed = true;

            return true;
        }

        if (self::MODE_INTERVAL === $this->mode) {
            $this->emit($this->cycleBuffer);
            $this->cycleBuffer = [];

            return false; // stay open — this !done was only a cycle boundary
        }

        // Clean !done/!empty on a /listen channel means RouterOS actually
        // closed the stream (normally after stop()/cancel()).
        $this->closed = true;

        return true;
    }

    private function emit(array $rows): void
    {
        if (null !== $this->onData) {
            ($this->onData)($rows);

            return;
        }

        $this->pending[] = $rows;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function error(): ?RequestException
    {
        return $this->error;
    }

    public function hasPending(): bool
    {
        return [] !== $this->pending;
    }

    /**
     * Pull-style consumption: block (cooperatively) until a payload is
     * buffered or the channel closes.
     *
     * @return array<int, array<string, string>>|null null once the channel
     *         has closed and nothing is left buffered.
     */
    public function wait(): ?array
    {
        if (null === $this->connection) {
            throw new LogicException('Channel is not attached to a Connection');
        }

        $this->connection->waitUntil(fn () => $this->hasPending() || $this->closed);

        if ($this->hasPending()) {
            return array_shift($this->pending);
        }

        if (null !== $this->error) {
            throw $this->error;
        }

        return null;
    }

    /**
     * Ask RouterOS to cancel this stream (`/cancel =tag=<this channel's
     * tag>`) and wait for it to actually close.
     */
    public function stop(): void
    {
        if ($this->closed || null === $this->connection || null === $this->tag) {
            return;
        }

        $this->connection->write('/cancel', ['=tag=' . $this->tag]);
        $this->connection->waitUntil(fn () => $this->closed);
    }
}
