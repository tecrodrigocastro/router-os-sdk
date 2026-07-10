<?php

namespace RouterOS\Sdk\Tests\Transport;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Exceptions\TransportException;
use RouterOS\Sdk\Transport\StreamTransport;

/**
 * Exercises StreamTransport against a real loopback TCP socket (no mocking
 * of PHP's stream functions) so the chunked-read/write and close-detection
 * logic is proven end-to-end, without needing a real RouterOS device.
 */
final class StreamTransportTest extends TestCase
{
    /** @return array{0: resource, 1: string, 2: int} server, host, port */
    private function startLoopbackServer(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "Failed to start loopback server: $errstr");

        $name = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $name);

        return [$server, $host, (int) $port];
    }

    public function testReadReturnsExactlyRequestedByteCountAcrossMultiplePackets(): void
    {
        [$server, $host, $port] = $this->startLoopbackServer();

        try {
            $client = StreamTransport::connect($host, $port, tls: false, connectTimeoutSec: 2, readTimeoutSec: 2);
            $conn   = stream_socket_accept($server, 2);
            $this->assertNotFalse($conn);

            // Send in two separate writes to prove read() assembles a
            // fragmented TCP stream into the exact length requested.
            fwrite($conn, 'ABC');
            fwrite($conn, 'DE');

            $this->assertSame('ABCDE', $client->read(5));

            $client->close();
            fclose($conn);
        } finally {
            fclose($server);
        }
    }

    public function testWriteDeliversAllBytes(): void
    {
        [$server, $host, $port] = $this->startLoopbackServer();

        try {
            $client = StreamTransport::connect($host, $port, tls: false, connectTimeoutSec: 2, readTimeoutSec: 2);
            $conn   = stream_socket_accept($server, 2);
            $this->assertNotFalse($conn);

            $payload = str_repeat('x', 70000); // larger than a single fwrite chunk on most platforms
            $client->write($payload);

            $received = '';
            stream_set_timeout($conn, 2);
            while (strlen($received) < strlen($payload)) {
                $chunk = fread($conn, 65536);
                if ('' === $chunk || false === $chunk) {
                    break;
                }
                $received .= $chunk;
            }

            $this->assertSame($payload, $received);

            $client->close();
            fclose($conn);
        } finally {
            fclose($server);
        }
    }

    public function testReadThrowsWhenPeerClosesConnection(): void
    {
        [$server, $host, $port] = $this->startLoopbackServer();

        try {
            $client = StreamTransport::connect($host, $port, tls: false, connectTimeoutSec: 2, readTimeoutSec: 2);
            $conn   = stream_socket_accept($server, 2);
            $this->assertNotFalse($conn);

            fclose($conn); // peer hangs up before sending anything

            $this->expectException(TransportException::class);
            $client->read(1);
        } finally {
            fclose($server);
        }
    }

    public function testConnectToUnreachableHostThrows(): void
    {
        $this->expectException(TransportException::class);
        // Port 1 on loopback: reserved, nothing listens there — fails fast.
        StreamTransport::connect('127.0.0.1', 1, tls: false, connectTimeoutSec: 1, readTimeoutSec: 1);
    }
}
