<?php

namespace RouterOS\Sdk\Transport;

use Fiber;
use RouterOS\Sdk\Exceptions\TransportException;
use RouterOS\Sdk\Io\Reactor;

/**
 * PHP-stream transport (stream_socket_client). Works everywhere — no
 * extension beyond core PHP required.
 *
 * Ported from evilfreelancer/routeros-api-php's SocketTrait::openSocket().
 *
 * Blocking by default: read() blocks the whole process, same as that
 * reference implementation — correct and sufficient for a single caller.
 * If a Reactor is supplied, read() instead switches the socket to
 * non-blocking mode and, when no data is ready yet, suspends the current
 * Fiber via Reactor::waitForReadable() instead of blocking — this is what
 * lets a real concurrent write()/listen() pair actually interleave on one
 * socket instead of freezing the process on whichever one reads first.
 */
final class StreamTransport implements TransportInterface
{
    /** @var resource|null */
    private $socket;
    private bool $closed = false;
    private bool $nonBlocking = false;

    /**
     * @param array<string, mixed> $sslOptions    passed as the 'ssl' stream context options
     * @param array<string, mixed> $socketOptions passed as the 'socket' stream context options (e.g. tcp_nodelay, bindto)
     */
    public static function connect(
        string $host,
        int $port,
        bool $tls = false,
        int $connectTimeoutSec = 10,
        int $readTimeoutSec = 30,
        array $sslOptions = [],
        array $socketOptions = [],
        ?Reactor $reactor = null,
    ): self {
        $contextOptions = [];
        if (!empty($sslOptions)) {
            $contextOptions['ssl'] = $sslOptions;
        }
        if (!empty($socketOptions)) {
            $contextOptions['socket'] = $socketOptions;
        }

        $context = stream_context_create($contextOptions);
        $scheme  = $tls ? 'ssl://' : 'tcp://';

        $socket = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errorNumber,
            $errorString,
            $connectTimeoutSec,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (false === $socket || !is_resource($socket)) {
            throw new TransportException("Unable to connect to {$host}:{$port}: {$errorString}", $errorNumber);
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, $readTimeoutSec);

        return new self($socket, $reactor);
    }

    /** @param resource $socket */
    private function __construct($socket, private readonly ?Reactor $reactor = null)
    {
        $this->socket = $socket;
    }

    public function read(int $length): string
    {
        if ($this->closed || null === $this->socket) {
            throw new TransportException('Cannot read from a closed transport');
        }

        if (0 === $length) {
            return '';
        }

        if (null !== $this->reactor && null !== Fiber::getCurrent()) {
            return $this->readViaReactor($length);
        }

        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($this->socket, $length - strlen($buffer));

            if (false === $chunk || ('' === $chunk && feof($this->socket))) {
                $this->close();
                throw new TransportException('Connection closed while reading');
            }

            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out']) {
                throw new TransportException('Socket read timed out');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function readViaReactor(int $length): string
    {
        if (!$this->nonBlocking) {
            stream_set_blocking($this->socket, false);
            $this->nonBlocking = true;
        }

        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($this->socket, $length - strlen($buffer));

            if (false === $chunk) {
                $this->close();
                throw new TransportException('Connection closed while reading');
            }

            if ('' === $chunk) {
                if (feof($this->socket)) {
                    $this->close();
                    throw new TransportException('Connection closed while reading');
                }

                // No data ready yet — genuinely suspend this Fiber instead
                // of blocking the process; Reactor::tick() (driven by
                // whoever wants concurrent operations to progress) resumes
                // us once the socket is readable.
                $this->reactor->waitForReadable($this->socket);
                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function write(string $data): int
    {
        if ($this->closed || null === $this->socket) {
            throw new TransportException('Cannot write to a closed transport');
        }

        $total  = strlen($data);
        $written = 0;

        while ($written < $total) {
            $sent = fwrite($this->socket, substr($data, $written));

            if (false === $sent || 0 === $sent) {
                $this->close();
                throw new TransportException('Connection closed while writing');
            }

            $written += $sent;
        }

        return $written;
    }

    public function waitReadable(int $timeoutMs): bool
    {
        if ($this->closed || null === $this->socket) {
            return false;
        }

        $read   = [$this->socket];
        $write  = null;
        $except = null;

        $ready = @stream_select($read, $write, $except, intdiv($timeoutMs, 1000), ($timeoutMs % 1000) * 1000);

        return false !== $ready && $ready > 0;
    }

    public function close(): void
    {
        if (!$this->closed && is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->closed = true;
        $this->socket = null;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
