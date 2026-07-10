<?php

namespace RouterOS\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Query;
use RouterOS\Sdk\Transport\FakeTransport;

final class ClientTest extends TestCase
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

    public function testWriteReturnsRows(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=ether1', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));

        $this->assertSame([['name' => 'ether1']], $client->write('/interface/print'));
    }

    public function testQueryBuilderRoundTripsThroughClient(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));
        $query  = new Query('/interface/print');
        $query->where('disabled', 'false');

        $client->query($query);

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('?disabled=false', $sent);
    }

    public function testListenReturnsUsableChannel(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!re', ['=address=10.0.0.5', '.tag=1']));

        $client  = Client::fromConnection(new Connection($transport));
        $channel = $client->listen('/ip/arp/listen');

        $this->assertSame([['address' => '10.0.0.5']], $channel->wait());
    }

    public function testIntervalReturnsUsableChannel(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=cpu-load=5', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client  = Client::fromConnection(new Connection($transport));
        $channel = $client->interval('/system/resource/print', 2);

        $this->assertSame([['cpu-load' => '5']], $channel->wait());
        $this->assertFalse($channel->isClosed());
    }

    public function testCloseClosesUnderlyingTransport(): void
    {
        $transport = new FakeTransport();
        $client = Client::fromConnection(new Connection($transport));

        $client->close();

        $this->assertTrue($client->isClosed());
        $this->assertTrue($transport->isClosed());
    }
}
