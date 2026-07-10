<?php

namespace RouterOS\Sdk\Tests\Isp;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class PppProfilesTest extends TestCase
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
        $client->pppProfiles()->create('vip', rateLimit: '50M/50M', localAddress: '10.0.0.1', remoteAddress: '10.0.0.0/24');

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ppp/profile/add', $sent);
        $this->assertStringContainsString('=name=vip', $sent);
        $this->assertStringContainsString('=rate-limit=50M/50M', $sent);
        $this->assertStringContainsString('=local-address=10.0.0.1', $sent);
        $this->assertStringContainsString('=remote-address=10.0.0.0/24', $sent);
    }

    public function testFindReturnsProfileOrNull(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));

        $this->assertNull($client->pppProfiles()->find('ghost'));
    }

    public function testSetRateLimitUpdatesTheMatchedProfile(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*2', '=name=vip', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $profiles = $client->pppProfiles();

        $result = null;
        $fiber = new \Fiber(function () use ($profiles, &$result) {
            $result = $profiles->setRateLimit('vip', '100M/100M');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($result);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ppp/profile/set', $sent);
        $this->assertStringContainsString('=rate-limit=100M/100M', $sent);
    }

    public function testRemoveReturnsFalseWhenNothingMatches(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));

        $this->assertFalse($client->pppProfiles()->remove('ghost'));
    }

    public function testAllListsEveryProfile(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=default', '.tag=1'])
            . $this->sentenceBytes('!re', ['=name=vip', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));

        $this->assertSame([['name' => 'default'], ['name' => 'vip']], $client->pppProfiles()->all());
    }
}
