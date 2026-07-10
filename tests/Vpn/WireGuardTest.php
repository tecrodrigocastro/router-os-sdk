<?php

namespace RouterOS\Sdk\Tests\Vpn;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;
use RuntimeException;

final class WireGuardTest extends TestCase
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

    public function testCreateInterfaceSendsExpectedWordsAndReturnsInfo(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));
        $wg = $client->wireGuard('to-hq');

        $info = null;
        $fiber = new \Fiber(function () use ($wg, &$info) {
            $info = $wg->createInterface(listenPort: 51821);
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended(), 'expected to block waiting for the info() reply (tag 2)');
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=to-hq', '=public-key=abc123=', '.tag=2'])
            . $this->sentenceBytes('!done', ['.tag=2'])
        );

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame(['name' => 'to-hq', 'public-key' => 'abc123='], $info);
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/interface/wireguard/add', $sent);
        $this->assertStringContainsString('=name=to-hq', $sent);
        $this->assertStringContainsString('=listen-port=51821', $sent);
    }

    public function testCreateInterfaceWithExplicitPrivateKey(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));
        $wg = $client->wireGuard('to-hq');

        $fiber = new \Fiber(function () use ($wg) {
            $wg->createInterface(privateKey: 'mysecretkey=');
        });
        $fiber->start();
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=2']));

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('=private-key=mysecretkey=', $sent);
    }

    public function testAddPeerSendsAllProvidedFields(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=1']));

        $client = Client::fromConnection(new Connection($transport));
        $client->wireGuard('to-hq')->addPeer(
            publicKey: 'peerpubkey=',
            allowedAddress: '10.200.0.2/32',
            endpointHost: 'vpn.example.com',
            endpointPort: 51820,
            presharedKey: 'psk=',
            persistentKeepaliveSeconds: 25,
            comment: 'branch-office-1',
        );

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/interface/wireguard/peers/add', $sent);
        $this->assertStringContainsString('=interface=to-hq', $sent);
        $this->assertStringContainsString('=public-key=peerpubkey=', $sent);
        $this->assertStringContainsString('=allowed-address=10.200.0.2/32', $sent);
        $this->assertStringContainsString('=endpoint-address=vpn.example.com', $sent);
        $this->assertStringContainsString('=endpoint-port=51820', $sent);
        $this->assertStringContainsString('=preshared-key=psk=', $sent);
        $this->assertStringContainsString('=persistent-keepalive=25s', $sent);
        $this->assertStringContainsString('=comment=branch-office-1', $sent);
    }

    public function testRemovePeerAndPeersList(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*1', '=public-key=peerpubkey=', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $wg = $client->wireGuard('to-hq');

        $removed = null;
        $fiber = new \Fiber(function () use ($wg, &$removed) {
            $removed = $wg->removePeer('peerpubkey=');
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        $this->assertTrue($removed);
        $this->assertStringContainsString('/interface/wireguard/peers/remove', implode('', $transport->writtenLog()));
    }

    public function testPeersListsOnlyThisInterfacesPeers(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=public-key=a=', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $client = Client::fromConnection(new Connection($transport));
        $peers = $client->wireGuard('to-hq')->peers();

        $this->assertSame([['public-key' => 'a=']], $peers);
        $this->assertStringContainsString('?interface=to-hq', implode('', $transport->writtenLog()));
    }

    public function testWireGuardIsCachedPerInterfaceName(): void
    {
        $client = Client::fromConnection(new Connection(new FakeTransport()));

        $this->assertSame($client->wireGuard('to-hq'), $client->wireGuard('to-hq'));
        $this->assertNotSame($client->wireGuard('to-hq'), $client->wireGuard('to-branch'));
    }

    public function testGenerateKeypairThrowsAClearErrorWithoutSodium(): void
    {
        if (extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium is loaded in this environment; this test covers its absence.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sodium/i');
        \RouterOS\Sdk\Vpn\WireGuard::generateKeypair();
    }

    public function testGenerateKeypairProducesTwoDistinctBase64Keys(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium is not loaded in this environment.');
        }

        $keys = \RouterOS\Sdk\Vpn\WireGuard::generateKeypair();

        $this->assertArrayHasKey('private', $keys);
        $this->assertArrayHasKey('public', $keys);
        $this->assertNotSame($keys['private'], $keys['public']);
        $this->assertSame(32, strlen(base64_decode($keys['private'], true)));
        $this->assertSame(32, strlen(base64_decode($keys['public'], true)));
    }
}
