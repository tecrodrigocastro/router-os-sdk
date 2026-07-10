<?php

namespace RouterOS\Sdk\Tests\Isp;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class PppSecretsTest extends TestCase
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
        $client->pppSecrets()->create('joao', 'segredo123', profile: 'vip', localAddress: '10.0.0.1', remoteAddress: '10.0.0.5');

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ppp/secret/add', $sent);
        $this->assertStringContainsString('=name=joao', $sent);
        $this->assertStringContainsString('=password=segredo123', $sent);
        $this->assertStringContainsString('=service=pppoe', $sent);
        $this->assertStringContainsString('=profile=vip', $sent);
        $this->assertStringContainsString('=local-address=10.0.0.1', $sent);
        $this->assertStringContainsString('=remote-address=10.0.0.5', $sent);
    }

    public function testFindReturnsTheSecretOrNull(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=joao', '=profile=default', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));

        $this->assertSame(['name' => 'joao', 'profile' => 'default'], $client->pppSecrets()->find('joao'));
    }

    public function testRemoveReturnsTrueWhenSomethingWasRemoved(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*1', '=name=joao', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $secrets = $client->pppSecrets();

        $result = null;
        $fiber = new \Fiber(function () use ($secrets, &$result) {
            $result = $secrets->remove('joao');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($fiber->isTerminated());
        $this->assertTrue($result);
        $this->assertStringContainsString('/ppp/secret/remove', implode('', $transport->writtenLog()));
    }

    public function testIsOnlineChecksActiveSessions(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));

        $this->assertFalse($client->pppSecrets()->isOnline('joao'));
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ppp/active/print', $sent);
        $this->assertStringContainsString('?name=joao', $sent);
    }

    public function testActiveSessionsWithoutNameListsAllSessions(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=joao', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $sessions = $client->pppSecrets()->activeSessions();

        $this->assertSame([['name' => 'joao']], $sessions);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringNotContainsString('?name=', $sent);
    }

    public function testKillRemovesActiveSessionsForThatSecret(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*3', '=name=joao', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $secrets = $client->pppSecrets();

        $count = null;
        $fiber = new \Fiber(function () use ($secrets, &$count) {
            $count = $secrets->kill('joao');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertSame(1, $count);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ppp/active/remove', $sent);
        $this->assertStringContainsString('=.id=*3', $sent);
    }
}
