<?php

namespace RouterOS\Sdk\Tests\Isp;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class SimpleQueueTest extends TestCase
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
        $client->simpleQueue()->create('joao', '10.0.0.5/32', '20M/20M');

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/queue/simple/add', $sent);
        $this->assertStringContainsString('=name=joao', $sent);
        $this->assertStringContainsString('=target=10.0.0.5/32', $sent);
        $this->assertStringContainsString('=max-limit=20M/20M', $sent);
    }

    public function testFindReturnsTheQueueOrNull(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));

        $this->assertNull($client->simpleQueue()->find('ghost'));
    }

    public function testSetMaxLimitUpdatesTheMatchedQueue(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*4', '=name=joao', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $queue = $client->simpleQueue();

        $result = null;
        $fiber = new \Fiber(function () use ($queue, &$result) {
            $result = $queue->setMaxLimit('joao', '5M/5M');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($result);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/queue/simple/set', $sent);
        $this->assertStringContainsString('=.id=*4', $sent);
        $this->assertStringContainsString('=max-limit=5M/5M', $sent);
    }

    public function testRemoveReturnsFalseWhenNothingMatches(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));

        $this->assertFalse($client->simpleQueue()->remove('ghost'));
    }

    public function testDisableAndEnableSetTheDisabledFlag(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*2', '=name=joao', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $queue = $client->simpleQueue();

        $result = null;
        $fiber = new \Fiber(function () use ($queue, &$result) {
            $result = $queue->disable('joao');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($result);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/queue/simple/set', $sent);
        $this->assertStringContainsString('=disabled=yes', $sent);
    }
}
