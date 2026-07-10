<?php

namespace RouterOS\Sdk\Transport;

/**
 * Byte-level transport used by the protocol layer. Implementations may be
 * blocking (StreamTransport) or Fiber-suspending / coroutine-yielding
 * (Reactor-backed StreamTransport, SwooleTransport) — the protocol layer
 * only ever calls read()/write() and does not care which.
 */
interface TransportInterface
{
    /**
     * Read exactly $length bytes. Blocks (or suspends the current Fiber)
     * until that many bytes are available, or throws on connection close.
     */
    public function read(int $length): string;

    /**
     * Write the full contents of $data. Returns the number of bytes written.
     */
    public function write(string $data): int;

    public function close(): void;

    public function isClosed(): bool;

    /**
     * Best-effort check: is at least one more byte already available to
     * read within $timeoutMs? Used only for the multi-block !done debounce
     * (some RouterOS devices split what's logically one response into
     * several !re...!done blocks) — never relied on for correctness, only
     * to decide whether to keep waiting a little longer before finalizing.
     */
    public function waitReadable(int $timeoutMs): bool;
}
