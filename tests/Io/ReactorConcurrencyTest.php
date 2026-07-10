<?php

namespace RouterOS\Sdk\Tests\Io;

use Fiber;
use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Io\Reactor;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\StreamTransport;

/**
 * Proves real concurrency: a write() and a listen() in flight at once on a
 * genuine TCP loopback socket (no FakeTransport), each in its own Fiber,
 * serviced by one Reactor — the socket read that would otherwise block the
 * whole process instead suspends only the Fiber that's waiting, letting
 * the other one make progress once its own data arrives.
 */
final class ReactorConcurrencyTest extends TestCase
{
    /** @var resource */
    private $listenSocket;

    protected function tearDown(): void
    {
        if (is_resource($this->listenSocket)) {
            fclose($this->listenSocket);
        }
    }

    private function sentenceBytes(string $type, array $words = []): string
    {
        $buffer = '';
        $sink   = new class ($buffer) implements \RouterOS\Sdk\Transport\TransportInterface {
            public array $chunks = [];
            public function __construct(private &$unused) {}
            public function read(int $length): string { return ''; }
            public function write(string $data): int { $this->chunks[] = $data; return strlen($data); }
            public function close(): void {}
            public function isClosed(): bool { return false; }
            public function waitReadable(int $timeoutMs): bool { return false; }
        };

        Word::write($sink, $type);
        foreach ($words as $word) {
            Word::write($sink, $word);
        }
        Word::write($sink, '');

        return implode('', $sink->chunks);
    }

    public function testWriteAndListenGenuinelyInterleaveOverARealSocket(): void
    {
        $this->listenSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($this->listenSocket, $errstr);
        $name = stream_socket_get_name($this->listenSocket, false);
        [$host, $port] = explode(':', $name);

        $reactor = new Reactor();
        $client  = StreamTransport::connect($host, (int) $port, tls: false, connectTimeoutSec: 2, readTimeoutSec: 5, reactor: $reactor);
        $server  = stream_socket_accept($this->listenSocket, 2);
        $this->assertNotFalse($server);
        stream_set_blocking($server, true);

        $connection = new Connection($client, multiBlockDebounceMs: 5);

        $resultA = null;
        $fiberA  = new Fiber(function () use ($connection, &$resultA) {
            $resultA = $connection->write('/interface/print');
        });

        $resultB = null;
        $fiberB  = new Fiber(function () use ($connection, &$resultB) {
            $channel = $connection->listen('/ip/arp/listen');
            $resultB = $channel->wait();
        });

        // Both fibers send their command and then genuinely block — A on a
        // real non-blocking socket read via the Reactor, B parked behind A
        // in Connection's own single-pumper guard.
        $fiberA->start();
        $this->assertTrue($fiberA->isSuspended());
        $fiberB->start();
        $this->assertTrue($fiberB->isSuspended());
        $this->assertTrue($reactor->hasWaiters(), 'expected the Reactor to have a registered socket waiter');

        // Server answers A's command (tag 1) only.
        fwrite($server, $this->sentenceBytes('!re', ['=name=ether1', '.tag=1']));
        fwrite($server, $this->sentenceBytes('!done', ['.tag=1']));

        // Drive the reactor until A is done — this is the loop a real
        // long-running consumer would run; nothing resumes fibers on its own.
        $deadline = microtime(true) + 5;
        while (!$fiberA->isTerminated() && microtime(true) < $deadline) {
            $reactor->tick(50);
        }
        $this->assertTrue($fiberA->isTerminated(), 'fiber A (write) did not complete in time');
        $this->assertSame([['name' => 'ether1']], $resultA);
        $this->assertFalse($fiberB->isTerminated(), 'fiber B (listen) should still be waiting for its own data');

        // Now the server pushes B's ARP event (tag 2).
        fwrite($server, $this->sentenceBytes('!re', ['=address=10.0.0.5', '.tag=2']));

        $deadline = microtime(true) + 5;
        while (!$fiberB->isTerminated() && microtime(true) < $deadline) {
            $reactor->tick(50);
        }
        $this->assertTrue($fiberB->isTerminated(), 'fiber B (listen) did not complete in time');
        $this->assertSame([['address' => '10.0.0.5']], $resultB);

        fclose($server);
    }
}
