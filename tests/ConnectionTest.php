<?php

namespace RouterOS\Sdk\Tests;

use Fiber;
use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Exceptions\RequestException;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class ConnectionTest extends TestCase
{
    private function sentenceBytes(string $type, array $words = []): string
    {
        $writer = new FakeTransport();
        Word::write($writer, $type);
        foreach ($words as $word) {
            Word::write($writer, $word);
        }
        Word::write($writer, '');

        return implode('', $writer->writtenLog());
    }

    public function testWriteReturnsRowsOnDone(): void
    {
        $transport = new FakeTransport();
        // Connection will assign tag "1" to the first write() call.
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=ether1', '=running=true', '.tag=1'])
            . $this->sentenceBytes('!re', ['=name=ether2', '=running=false', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $connection = new Connection($transport);
        $rows = $connection->write('/interface/print');

        $this->assertSame(
            [
                ['name' => 'ether1', 'running' => 'true'],
                ['name' => 'ether2', 'running' => 'false'],
            ],
            $rows
        );
    }

    public function testWriteThrowsOnTrap(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!trap', ['=message=failure: bad command', '.tag=1']));
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=1']));

        $connection = new Connection($transport);

        try {
            $connection->write('/does/not/exist');
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertSame('failure: bad command', $e->getMessage());
        }
    }

    public function testEmptyReplyResolvesAsZeroRows(): void
    {
        // RouterOS 7.18+ quirk: !empty instead of !done for zero-row results.
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $connection = new Connection($transport);
        $rows = $connection->write('/interface/print', ['?disabled=true']);

        $this->assertSame([], $rows);
    }

    public function testMultiBlockDoneIsAccumulatedIntoOneResult(): void
    {
        // wifi-qcom quirk: RouterOS answers one command with one !re...!done
        // block PER INTERFACE instead of a single block for the whole table.
        // All rows across every block must land in one result, in order.
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=client=aa:bb:cc:dd:ee:01', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1']) // block 1 for wlan1
            . $this->sentenceBytes('!re', ['=client=aa:bb:cc:dd:ee:02', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1']) // block 2 for wlan2
        );

        $connection = new Connection($transport, multiBlockDebounceMs: 5);
        $rows = $connection->write('/interface/wifi/registration-table/print');

        $this->assertSame(
            [
                ['client' => 'aa:bb:cc:dd:ee:01'],
                ['client' => 'aa:bb:cc:dd:ee:02'],
            ],
            $rows
        );
    }

    public function testSentenceForUnknownTagIsDroppedNotThrown(): void
    {
        $transport = new FakeTransport();
        // A trailing packet for a tag nobody registered (e.g. tag "99" —
        // stream already stopped), followed by the real response on tag 1.
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=foo=bar', '.tag=99'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $connection = new Connection($transport);
        $rows = $connection->write('/interface/print');

        $this->assertSame([], $rows);
    }

    public function testSendWritesEndpointWordsAndConnectionOwnedTag(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=1']));

        $connection = new Connection($transport);
        // A caller-supplied ".tag=" word must be ignored — Connection always
        // assigns its own, otherwise the dispatch table would desync.
        $connection->write('/ip/address/add', ['=address=10.0.0.1/24', '.tag=bogus']);

        $sentBytes = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ip/address/add', $sentBytes);
        $this->assertStringContainsString('=address=10.0.0.1/24', $sentBytes);
        $this->assertStringNotContainsString('.tag=bogus', $sentBytes);
        $this->assertStringContainsString('.tag=1', $sentBytes);
    }

    public function testTwoConcurrentWritesInterleavedOnTheWireBothResolveCorrectly(): void
    {
        // Proves the core multiplexing claim: two commands in flight at once
        // on one socket, each driven by its own Fiber (as Client will do),
        // with RouterOS interleaving their responses — each resolves with
        // exactly its own rows, no cross-talk between tags.
        $transport  = new FakeTransport();
        $connection = new Connection($transport);

        $resultA = null;
        $resultB = null;

        $fiberA = new Fiber(function () use ($connection, &$resultA) {
            $resultA = $connection->write('/interface/print');
        });
        $fiberB = new Fiber(function () use ($connection, &$resultB) {
            $resultB = $connection->write('/ip/address/print');
        });

        // A sends its command (tag 1) then blocks reading — no data yet —
        // suspending for real inside FakeTransport::read().
        $fiberA->start();
        $this->assertTrue($fiberA->isSuspended());

        // B sends its command (tag 2). A is parked (not pumping), so B
        // becomes the pumper and also blocks reading — still no data.
        $fiberB->start();
        $this->assertTrue($fiberB->isSuspended());

        // Now the server responds, interleaved: B's row and done arrive
        // before A's — resuming whichever fiber is currently the blocked
        // reader (B, since it became the pumper), which will dispatch B's
        // sentences, then A's, in wire order.
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=address=10.0.0.1/24', '.tag=2'])
            . $this->sentenceBytes('!re', ['=name=ether1', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=2'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $this->assertSame([['name' => 'ether1']], $resultA);
        $this->assertSame([['address' => '10.0.0.1/24']], $resultB);
    }

    public function testTwoTagsMultiplexedWithUnknownTagNoise(): void
    {
        // Lower-level dispatch proof, no Fibers needed: two Futures
        // registered on their own tags, interleaved wire data including a
        // sentence for a tag nobody owns (dropped per the UNREGISTEREDTAG
        // quirk), each Future ends up with exactly its own rows.
        $transport  = new FakeTransport();
        $connection = new Connection($transport);

        $tagA = $connection->allocateTag();
        $futureA = new \RouterOS\Sdk\Future();
        $connection->register($tagA, $futureA);
        $connection->send('/interface/print', [], $tagA);

        $tagB = $connection->allocateTag();
        $futureB = new \RouterOS\Sdk\Future();
        $connection->register($tagB, $futureB);
        $connection->send('/ip/address/print', [], $tagB);

        $transport->pushRead(
            $this->sentenceBytes('!re', ['=foo=bar', '.tag=99']) // unknown tag — dropped
            . $this->sentenceBytes('!re', ['=name=ether1', '.tag=' . $tagA])
            . $this->sentenceBytes('!re', ['=address=10.0.0.1/24', '.tag=' . $tagB])
            . $this->sentenceBytes('!done', ['.tag=' . $tagA])
            . $this->sentenceBytes('!done', ['.tag=' . $tagB])
        );

        // This test drives dispatch directly (bypassing write()'s own
        // debounce/markSettled bookkeeping), so it waits on terminalSeen()
        // rather than isSettled() — the latter is only ever flipped by
        // Connection::write() itself.
        $connection->waitUntil(fn () => $futureA->terminalSeen() && $futureB->terminalSeen());

        $this->assertSame([['name' => 'ether1']], $futureA->result());
        $this->assertSame([['address' => '10.0.0.1/24']], $futureB->result());
    }
}
