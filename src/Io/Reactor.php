<?php

namespace RouterOS\Sdk\Io;

use Fiber;
use LogicException;

/**
 * Minimal stream_select-based event loop: lets a Fiber genuinely suspend
 * while waiting for a real socket to become readable, instead of blocking
 * the whole process the way a plain blocking read does.
 *
 * This is what makes concurrent write()/listen() calls actually concurrent
 * against a *real* Connection (StreamTransport) rather than only against
 * FakeTransport in tests — FakeTransport can fake "blocking" with a plain
 * Fiber::suspend() because it has no real I/O to wait on; a real socket
 * read that would block needs something to periodically poll it and
 * resume whoever's waiting, which is exactly Reactor::tick()'s job.
 *
 * Nothing drives the loop automatically — plain PHP has no background
 * scheduler. Whoever wants several Fiber-wrapped operations to make
 * progress concurrently must call tick() in a loop until they're all done
 * (see ConnectionRealSocketConcurrencyTest for the pattern). Framework
 * integrations (Swoole/Hyperf, Laravel Octane) replace this with their own
 * scheduler instead of using Reactor at all — see SwooleTransport (planned).
 */
final class Reactor
{
    /** @var array<int, resource> */
    private array $sockets = [];

    /** @var array<int, Fiber[]> */
    private array $readWaiters = [];

    /**
     * Register the current Fiber as waiting for $socket to become readable,
     * then suspend it. Must be called from inside a Fiber. Returns once
     * tick() has observed the socket is readable — callers should re-check
     * their own condition (e.g. re-attempt the read) rather than assume
     * any particular amount of data is now available.
     */
    public function waitForReadable($socket): void
    {
        $fiber = Fiber::getCurrent();
        if (null === $fiber) {
            throw new LogicException('Reactor::waitForReadable() must be called from inside a Fiber');
        }

        $id                          = (int) $socket;
        $this->sockets[$id]          = $socket;
        $this->readWaiters[$id][]    = $fiber;

        Fiber::suspend();
    }

    /**
     * One pass of the loop: block up to $timeoutMs waiting for any
     * registered socket to become readable, then resume every Fiber
     * waiting on whichever sockets are ready.
     *
     * @return int how many Fibers were resumed this tick
     */
    public function tick(int $timeoutMs = 100): int
    {
        if ([] === $this->sockets) {
            return 0;
        }

        $read   = array_values($this->sockets);
        $write  = null;
        $except = null;

        $ready = @stream_select($read, $write, $except, intdiv($timeoutMs, 1000), ($timeoutMs % 1000) * 1000);

        if (false === $ready || 0 === $ready) {
            return 0;
        }

        $resumed = 0;
        foreach ($read as $socket) {
            $id = (int) $socket;
            $fibers = $this->readWaiters[$id] ?? [];
            unset($this->readWaiters[$id], $this->sockets[$id]);

            foreach ($fibers as $fiber) {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                    $resumed++;
                }
            }
        }

        return $resumed;
    }

    public function hasWaiters(): bool
    {
        return [] !== $this->sockets;
    }
}
