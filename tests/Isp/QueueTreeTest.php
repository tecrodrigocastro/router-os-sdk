<?php

namespace RouterOS\Sdk\Tests\Isp;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class QueueTreeTest extends TestCase
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

    public function testCreateSendsExpectedWords(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));
        $client->queueTree()->create('joao', 'total-download', packetMark: 'joao-mark', maxLimit: '20M');

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/queue/tree/add', $sent);
        $this->assertStringContainsString('=name=joao', $sent);
        $this->assertStringContainsString('=parent=total-download', $sent);
        $this->assertStringContainsString('=packet-mark=joao-mark', $sent);
        $this->assertStringContainsString('=max-limit=20M', $sent);
    }

    public function testFindReturnsQueueOrNull(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));

        $this->assertNull($client->queueTree()->find('ghost'));
    }

    public function testSetMaxLimitUpdatesTheMatchedQueue(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*3', '=name=joao', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $tree = $client->queueTree();

        $result = null;
        $fiber = new \Fiber(function () use ($tree, &$result) {
            $result = $tree->setMaxLimit('joao', '10M');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($result);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/queue/tree/set', $sent);
        $this->assertStringContainsString('=max-limit=10M', $sent);
    }

    public function testDisableSetsDisabledYes(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*3', '=name=joao', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $tree = $client->queueTree();

        $result = null;
        $fiber = new \Fiber(function () use ($tree, &$result) {
            $result = $tree->disable('joao');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($result);
        $this->assertStringContainsString('=disabled=yes', implode('', $transport->writtenLog()));
    }

    public function testRemoveReturnsFalseWhenNothingMatches(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));

        $this->assertFalse($client->queueTree()->remove('ghost'));
    }
}
