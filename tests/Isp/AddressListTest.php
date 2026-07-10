<?php

namespace RouterOS\Sdk\Tests\Isp;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class AddressListTest extends TestCase
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

    public function testBlockAddsAddressWhenNotAlreadyBlocked(): void
    {
        $transport = new FakeTransport();
        // isBlocked() check (tag 1): not found
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));
        $list = $client->addressList('morosos');

        $result = null;
        $fiber = new \Fiber(function () use ($list, &$result) {
            $result = $list->block('10.0.0.5', comment: 'Contrato #123');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended(), 'expected to block waiting for the /add reply (tag 2)');
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($fiber->isTerminated());
        $this->assertTrue($result);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ip/firewall/address-list/add', $sent);
        $this->assertStringContainsString('=list=morosos', $sent);
        $this->assertStringContainsString('=address=10.0.0.5', $sent);
        $this->assertStringContainsString('=comment=Contrato #123', $sent);
    }

    public function testBlockIsIdempotentWhenAlreadyBlocked(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=list=morosos', '=address=10.0.0.5', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $result = $client->addressList('morosos')->block('10.0.0.5');

        $this->assertFalse($result, 'block() must be a no-op when the address is already in the list');
        $sent = implode('', $transport->writtenLog());
        $this->assertStringNotContainsString('/ip/firewall/address-list/add', $sent);
    }

    public function testUnblockRemovesTheEntry(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*9', '=address=10.0.0.5', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $list = $client->addressList('morosos');

        $result = null;
        $fiber = new \Fiber(function () use ($list, &$result) {
            $result = $list->unblock('10.0.0.5');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($result);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/ip/firewall/address-list/remove', $sent);
        $this->assertStringContainsString('=.id=*9', $sent);
    }

    public function testAllReturnsEveryEntryInTheList(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=address=10.0.0.5', '.tag=1'])
            . $this->sentenceBytes('!re', ['=address=10.0.0.6', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $all = $client->addressList('morosos')->all();

        $this->assertSame([['address' => '10.0.0.5'], ['address' => '10.0.0.6']], $all);
        $this->assertStringContainsString('?list=morosos', implode('', $transport->writtenLog()));
    }

    public function testAddressListIsCachedPerListName(): void
    {
        $client = Client::fromConnection(new Connection(new FakeTransport()));

        $this->assertSame($client->addressList('morosos'), $client->addressList('morosos'));
        $this->assertNotSame($client->addressList('morosos'), $client->addressList('vip'));
    }
}
