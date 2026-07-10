<?php

namespace RouterOS\Sdk\Tests\Laravel;

use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Integrations\Laravel\Facade as RouterOs;
use RouterOS\Sdk\Integrations\Laravel\RouterOsManager;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class FacadeTest extends TestCase
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

    /**
     * The real manager binding uses Client::connect() (a real socket) — for
     * facade tests, rebind it with a fake connector, same technique as
     * RouterOsManagerTest, just wired through the container instead of
     * constructed directly.
     */
    private function bindFakeManager(array $transportsByConnection): void
    {
        // Each connection's config is just tagged with its own name, so the
        // connector below knows which transport to wrap.
        $configs = [];
        foreach ($transportsByConnection as $name => $transport) {
            $configs[$name] = ['__name' => $name];
        }

        $manager = new RouterOsManager(
            connectionsConfig: $configs,
            default: array_key_first($transportsByConnection),
            connector: function (array $config) use ($transportsByConnection) {
                return Client::fromConnection(new Connection($transportsByConnection[$config['__name']]));
            },
        );

        $this->app->instance(RouterOsManager::class, $manager);
    }

    public function testFacadeForwardsToDefaultConnection(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=ether1', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $this->bindFakeManager(['main' => $transport]);

        $rows = RouterOs::write('/interface/print');

        $this->assertSame([['name' => 'ether1']], $rows);
    }

    public function testFacadeReachesNamedConnection(): void
    {
        $mainTransport = new FakeTransport();
        $mainTransport->pushRead($this->sentenceBytes('!done', ['.tag=1']));

        $secondaryTransport = new FakeTransport();
        $secondaryTransport->pushRead(
            $this->sentenceBytes('!re', ['=name=ether5', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $this->bindFakeManager(['main' => $mainTransport, 'secondary' => $secondaryTransport]);

        $rows = RouterOs::connection('secondary')->write('/interface/print');

        $this->assertSame([['name' => 'ether5']], $rows);
    }
}
