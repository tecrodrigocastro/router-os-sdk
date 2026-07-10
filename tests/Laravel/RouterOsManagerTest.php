<?php

namespace RouterOS\Sdk\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Exceptions\ConfigException;
use RouterOS\Sdk\Integrations\Laravel\RouterOsManager;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

/**
 * RouterOsManager is plain PHP with no Laravel dependency of its own (the
 * ServiceProvider is what wires it into the container) — a fake connector
 * backed by FakeTransport is all that's needed here, no testbench required.
 */
final class RouterOsManagerTest extends TestCase
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

    public function testConnectionIsCachedAcrossCalls(): void
    {
        $transport = new FakeTransport();
        $connector = fn (array $config) => Client::fromConnection(new Connection($transport));

        $manager = new RouterOsManager(
            connectionsConfig: ['main' => ['host' => 'x']],
            default: 'main',
            connector: $connector,
        );

        $first  = $manager->connection('main');
        $second = $manager->connection('main');

        $this->assertSame($first, $second);
    }

    public function testDefaultConnectionIsUsedWhenNoNameGiven(): void
    {
        $transport = new FakeTransport();
        $connector = fn (array $config) => Client::fromConnection(new Connection($transport));

        $manager = new RouterOsManager(
            connectionsConfig: ['main' => ['host' => 'x']],
            default: 'main',
            connector: $connector,
        );

        $this->assertSame($manager->connection(), $manager->connection('main'));
    }

    public function testDifferentNamesGetIndependentConnections(): void
    {
        $connector = fn (array $config) => Client::fromConnection(new Connection(new FakeTransport()));

        $manager = new RouterOsManager(
            connectionsConfig: ['main' => ['host' => 'a'], 'secondary' => ['host' => 'b']],
            default: 'main',
            connector: $connector,
        );

        $main      = $manager->connection('main');
        $secondary = $manager->connection('secondary');

        $this->assertNotSame($main, $secondary);
        $this->assertSame($main, $manager->connection('main'));
        $this->assertSame($secondary, $manager->connection('secondary'));
    }

    public function testUnknownConnectionNameThrows(): void
    {
        $manager = new RouterOsManager(
            connectionsConfig: ['main' => ['host' => 'a']],
            default: 'main',
            connector: fn (array $config) => Client::fromConnection(new Connection(new FakeTransport())),
        );

        $this->expectException(ConfigException::class);
        $manager->connection('does-not-exist');
    }

    public function testClosedConnectionIsRebuiltOnNextAccess(): void
    {
        $builds = [];

        $manager = new RouterOsManager(
            connectionsConfig: ['main' => ['host' => 'a']],
            default: 'main',
            connector: function (array $config) use (&$builds) {
                $transport = new FakeTransport();
                $client    = Client::fromConnection(new Connection($transport));
                $builds[]  = $client;

                return $client;
            },
        );

        $first = $manager->connection('main');
        $this->assertCount(1, $builds);

        // Simulate a dead connection exactly how it happens for real: the
        // underlying transport reports closed (e.g. after a TransportException).
        $first->close();

        $second = $manager->connection('main');

        $this->assertCount(2, $builds, 'expected a fresh Client to be built once the cached one was closed');
        $this->assertNotSame($first, $second);
    }

    public function testForgetConnectionForcesRebuildEvenIfNotClosed(): void
    {
        $builds = [];

        $manager = new RouterOsManager(
            connectionsConfig: ['main' => ['host' => 'a']],
            default: 'main',
            connector: function (array $config) use (&$builds) {
                $client   = Client::fromConnection(new Connection(new FakeTransport()));
                $builds[] = $client;

                return $client;
            },
        );

        $first = $manager->connection('main');
        $this->assertFalse($first->isClosed());

        $manager->forgetConnection('main');
        $second = $manager->connection('main');

        $this->assertCount(2, $builds);
        $this->assertNotSame($first, $second);
    }

    public function testMagicCallForwardsToDefaultConnection(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=ether1', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $manager = new RouterOsManager(
            connectionsConfig: ['main' => ['host' => 'a']],
            default: 'main',
            connector: fn (array $config) => Client::fromConnection(new Connection($transport)),
        );

        $rows = $manager->write('/interface/print');

        $this->assertSame([['name' => 'ether1']], $rows);
    }
}
