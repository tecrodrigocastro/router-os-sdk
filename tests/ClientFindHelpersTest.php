<?php

namespace RouterOS\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class ClientFindHelpersTest extends TestCase
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

    public function testFindWhereSendsOneFilterWordPerEntryAndReturnsRows(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=joao', '=profile=default', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $rows = $client->findWhere('/ppp/secret', ['name' => 'joao', 'service' => 'pppoe']);

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ppp/secret/print', $sent);
        $this->assertStringContainsString('?name=joao', $sent);
        $this->assertStringContainsString('?service=pppoe', $sent);
        $this->assertSame([['name' => 'joao', 'profile' => 'default']], $rows);
    }

    public function testFindWhereStripsTrailingSlashFromResource(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));
        $client->findWhere('/ppp/secret/');

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ppp/secret/print', $sent);
        $this->assertStringNotContainsString('/ppp/secret//print', $sent);
    }

    public function testFindOneReturnsFirstRowOrNull(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=joao', '.tag=1'])
            . $this->sentenceBytes('!re', ['=name=maria', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );
        $client = Client::fromConnection(new Connection($transport));

        $this->assertSame(['name' => 'joao'], $client->findOne('/ppp/secret', ['service' => 'pppoe']));
    }

    public function testFindOneReturnsNullOnEmptyResult(): void
    {
        // RouterOS 7.18+ quirk: !empty instead of !done for zero-row results.
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));
        $client = Client::fromConnection(new Connection($transport));

        $this->assertNull($client->findOne('/ppp/secret', ['name' => 'ghost']));
    }

    public function testRemoveWhereRemovesEachMatchedRowAndReturnsCount(): void
    {
        // removeWhere() is one synchronous call that internally sends
        // three commands (print, then remove x2) — a real router can't
        // reply to the second/third before they're actually sent, so drive
        // it in a Fiber and push each reply only once it's genuinely
        // blocked waiting (same technique as AuthenticatorTest's legacy
        // login test), rather than pre-buffering everything up front.
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*1', '=name=joao', '.tag=1'])
            . $this->sentenceBytes('!re', ['=.id=*2', '=name=joao', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));

        $count = null;
        $fiber = new \Fiber(function () use ($client, &$count) {
            $count = $client->removeWhere('/ppp/secret', ['name' => 'joao']);
        });

        $fiber->start();
        $this->assertTrue($fiber->isSuspended(), 'expected to block waiting for the first /remove reply (tag 2)');
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($fiber->isSuspended(), 'expected to block waiting for the second /remove reply (tag 3)');
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=3']));

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame(2, $count);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ppp/secret/remove', $sent);
        $this->assertStringContainsString('=.id=*1', $sent);
        $this->assertStringContainsString('=.id=*2', $sent);
    }

    public function testRemoveWhereReturnsZeroWhenNothingMatches(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));
        $client = Client::fromConnection(new Connection($transport));

        $this->assertSame(0, $client->removeWhere('/ppp/secret', ['name' => 'ghost']));
    }

    public function testSetWhereSendsIdAndUpdatesForEachMatchedRow(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*7', '=name=joao', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));

        $count = null;
        $fiber = new \Fiber(function () use ($client, &$count) {
            $count = $client->setWhere('/ppp/secret', ['name' => 'joao'], ['profile' => 'vip']);
        });

        $fiber->start();
        $this->assertTrue($fiber->isSuspended(), 'expected to block waiting for the /set reply (tag 2)');
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame(1, $count);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ppp/secret/set', $sent);
        $this->assertStringContainsString('=.id=*7', $sent);
        $this->assertStringContainsString('=profile=vip', $sent);
    }
}
