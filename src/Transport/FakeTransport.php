<?php

namespace RouterOS\Sdk\Transport;

use Fiber;
use RouterOS\Sdk\Exceptions\TransportException;

/**
 * In-memory transport double for tests. Feed it the raw bytes RouterOS would
 * have sent with pushRead(), then hand it to a Connection/SentenceReader
 * exactly like a real socket. Everything written via write() is captured in
 * writtenLog() for assertions.
 *
 * Blocking-read semantics: if read() is called from inside a Fiber and not
 * enough bytes are buffered yet, it suspends that Fiber (mirroring a real
 * socket read blocking) instead of throwing — pushRead() resumes it once
 * more data arrives. Outside a Fiber there's no way to "wait", so it throws
 * immediately, same as before.
 */
class FakeTransport implements TransportInterface
{
    private string $readBuffer = '';
    /** @var string[] */
    private array $written = [];
    private bool $closed = false;
    private ?Fiber $blockedReader = null;

    public function __construct(string $initialReadBuffer = '')
    {
        $this->readBuffer = $initialReadBuffer;
    }

    public function pushRead(string $bytes): void
    {
        $this->readBuffer .= $bytes;

        if (null !== $this->blockedReader && $this->blockedReader->isSuspended()) {
            $fiber = $this->blockedReader;
            $this->blockedReader = null;
            $fiber->resume();
        }
    }

    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        while (strlen($this->readBuffer) < $length) {
            if ($this->closed) {
                throw new TransportException('Cannot read from a closed transport');
            }

            if (null === Fiber::getCurrent()) {
                throw new TransportException('End of stream: requested ' . $length . ' bytes, only ' . strlen($this->readBuffer) . ' buffered');
            }

            $this->blockedReader = Fiber::getCurrent();
            Fiber::suspend();
            $this->blockedReader = null;
        }

        $result = substr($this->readBuffer, 0, $length);
        $this->readBuffer = substr($this->readBuffer, $length);

        return $result;
    }

    public function write(string $data): int
    {
        if ($this->closed) {
            throw new TransportException('Cannot write to a closed transport');
        }

        $this->written[] = $data;

        return strlen($data);
    }

    public function close(): void
    {
        $this->closed = true;

        if (null !== $this->blockedReader && $this->blockedReader->isSuspended()) {
            $fiber = $this->blockedReader;
            $this->blockedReader = null;
            $fiber->resume();
        }
    }

    /**
     * No real waiting for an in-memory buffer — tests control exactly what
     * bytes are queued, so "readable" just means "buffer is non-empty".
     */
    public function waitReadable(int $timeoutMs): bool
    {
        return '' !== $this->readBuffer;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** @return string[] Every chunk passed to write(), in order. */
    public function writtenLog(): array
    {
        return $this->written;
    }

    public function pendingReadBytes(): int
    {
        return strlen($this->readBuffer);
    }
}
