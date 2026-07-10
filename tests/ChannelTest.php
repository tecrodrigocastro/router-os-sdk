<?php

namespace RouterOS\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class ChannelTest extends TestCase
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

    public function testListenChannelDeliversEachRowAsItsOwnEventAndStaysOpen(): void
    {
        $transport = new FakeTransport();
        // A /listen stream: RouterOS pushes one !re per change, no !done in
        // between — the tag must stay open across multiple events.
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=address=10.0.0.5', '=mac-address=AA:BB:CC:DD:EE:01', '.tag=1'])
            . $this->sentenceBytes('!re', ['=address=10.0.0.6', '=mac-address=AA:BB:CC:DD:EE:02', '.tag=1'])
        );

        $connection = new Connection($transport);
        $channel    = $connection->listen('/ip/arp/listen');

        $first  = $channel->wait();
        $second = $channel->wait();

        $this->assertSame([['address' => '10.0.0.5', 'mac-address' => 'AA:BB:CC:DD:EE:01']], $first);
        $this->assertSame([['address' => '10.0.0.6', 'mac-address' => 'AA:BB:CC:DD:EE:02']], $second);
        $this->assertFalse($channel->isClosed());
    }

    public function testListenChannelStopSendsCancelAndCloses(): void
    {
        $transport = new FakeTransport();
        $connection = new Connection($transport);
        $channel = $connection->listen('/ip/arp/listen'); // tag 1

        // stop() will send "/cancel =tag=1" as its own command (tag 2).
        // RouterOS responds to the cancelled stream with a !trap+!done on
        // tag 1, and to the /cancel command itself with !done on tag 2.
        $transport->pushRead(
            $this->sentenceBytes('!trap', ['=message=interrupted', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=2'])
        );

        $channel->stop();

        $this->assertTrue($channel->isClosed());
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/cancel', $sent);
        $this->assertStringContainsString('=tag=1', $sent);
    }

    public function testIntervalChannelDeliversOneCyclePerDoneAndStaysOpen(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=cpu-load=5', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1']) // cycle 1 boundary, NOT a close
            . $this->sentenceBytes('!re', ['=cpu-load=7', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1']) // cycle 2 boundary
        );

        $connection = new Connection($transport);
        $channel    = $connection->interval('/system/resource/print', 2);

        $cycle1 = $channel->wait();
        $cycle2 = $channel->wait();

        $this->assertSame([['cpu-load' => '5']], $cycle1);
        $this->assertSame([['cpu-load' => '7']], $cycle2);
        $this->assertFalse($channel->isClosed(), 'interval !done must not close the channel');

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('=interval=2', $sent);
    }

    public function testIntervalChannelHandlesEmptyCycle(): void
    {
        // RouterOS 7.18+ quirk applies to interval streams too: a cycle with
        // zero matching rows can arrive as !empty instead of a bare !done.
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $connection = new Connection($transport);
        $channel    = $connection->interval('/system/resource/print', 2, ['?disabled=true']);

        $this->assertSame([], $channel->wait());
        $this->assertFalse($channel->isClosed());
    }

    public function testOnDataCallbackFiresWhenAnyPumpDispatchesItsTag(): void
    {
        $transport = new FakeTransport();
        // Channel's event (tag 1) is buffered on the wire ahead of an
        // unrelated one-shot command's reply (tag 2) — reading through to
        // tag 2's response necessarily dispatches tag 1's event first.
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=address=10.0.0.5', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=2'])
        );

        $connection = new Connection($transport);
        $channel    = $connection->listen('/ip/arp/listen');

        $received = null;
        $channel->onData(function (array $rows) use (&$received) {
            $received = $rows;
        });

        $connection->write('/some/other/command');

        $this->assertSame([['address' => '10.0.0.5']], $received);
    }
}
