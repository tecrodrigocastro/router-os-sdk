<?php

namespace RouterOS\Sdk\Tests\Isp;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class FirewallTest extends TestCase
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

    public function testEnsureRuleCreatesTheRuleWhenAbsent(): void
    {
        $transport = new FakeTransport();
        // ruleExists() check (tag 1): nothing found
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));
        $firewall = $client->firewall();

        $created = null;
        $fiber = new \Fiber(function () use ($firewall, &$created) {
            $created = $firewall->ensureRule('filter', [
                'chain'            => 'forward',
                'src-address-list' => 'morosos',
                'action'           => 'drop',
            ], 'block-morosos');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended(), 'expected to block waiting for the /add reply (tag 2)');
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($fiber->isTerminated());
        $this->assertTrue($created);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ip/firewall/filter/add', $sent);
        $this->assertStringContainsString('=chain=forward', $sent);
        $this->assertStringContainsString('=src-address-list=morosos', $sent);
        $this->assertStringContainsString('=action=drop', $sent);
        $this->assertStringContainsString('=comment=block-morosos', $sent);
    }

    public function testEnsureRuleIsANoOpWhenAlreadyPresent(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=comment=block-morosos', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $created = $client->firewall()->ensureRule('filter', ['chain' => 'forward'], 'block-morosos');

        $this->assertFalse($created);
        $this->assertStringNotContainsString('/ip/firewall/filter/add', implode('', $transport->writtenLog()));
    }

    public function testRuleExistsReflectsPresence(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));

        $this->assertFalse($client->firewall()->ruleExists('nat', 'some-comment'));
        $this->assertStringContainsString('?comment=some-comment', implode('', $transport->writtenLog()));
    }

    public function testRemoveRuleRemovesByComment(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*1', '=comment=block-morosos', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $firewall = $client->firewall();

        $removed = null;
        $fiber = new \Fiber(function () use ($firewall, &$removed) {
            $removed = $firewall->removeRule('filter', 'block-morosos');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($removed);
        $this->assertStringContainsString('/ip/firewall/filter/remove', implode('', $transport->writtenLog()));
    }
}
