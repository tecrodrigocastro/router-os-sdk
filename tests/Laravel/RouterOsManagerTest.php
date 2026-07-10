<?php

namespace RouterOS\Sdk\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Exceptions\ConfigException;
use RouterOS\Sdk\Exceptions\TransportException;
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

    public function testCooldownSkipsReconnectAttemptAfterAFailure(): void
    {
        $attempts = 0;
        $clock    = 1000.0; // fake microtime(true), advanced manually below

        $manager = new RouterOsManager(
            connectionsConfig: ['main' => ['host' => 'a']],
            default: 'main',
            connector: function () use (&$attempts) {
                $attempts++;

                throw new TransportException('router unreachable');
            },
            reconnectCooldownSeconds: 5.0,
            now: function () use (&$clock) {
                return $clock;
            },
        );

        try {
            $manager->connection('main');
            $this->fail('expected the first attempt to throw');
        } catch (TransportException) {
        }
        $this->assertSame(1, $attempts);

        // Still within the 5s cooldown — must NOT touch the connector again.
        $clock += 2.0;
        try {
            $manager->connection('main');
            $this->fail('expected the cooldown to still be active');
        } catch (TransportException $e) {
            $this->assertStringContainsString('skipping reconnect attempt', $e->getMessage());
        }
        $this->assertSame(1, $attempts, 'connector must not be called again during the cooldown window');

        // Past the cooldown — attempts again (and fails again, recording a new timestamp).
        $clock += 10.0;
        try {
            $manager->connection('main');
            $this->fail('expected another real attempt past the cooldown');
        } catch (TransportException) {
        }
        $this->assertSame(2, $attempts, 'connector must be retried once the cooldown has elapsed');
    }

    public function testFreshFailureAfterAnInterveningSuccessGetsItsOwnCooldown(): void
    {
        $clock      = 1000.0;
        $shouldFail = true;
        $attempts   = 0;
        $lastClient = null;

        $manager = new RouterOsManager(
            connectionsConfig: ['main' => ['host' => 'a']],
            default: 'main',
            connector: function () use (&$shouldFail, &$attempts, &$lastClient) {
                $attempts++;
                if ($shouldFail) {
                    throw new TransportException('router unreachable');
                }

                return $lastClient = Client::fromConnection(new Connection(new FakeTransport()));
            },
            reconnectCooldownSeconds: 5.0,
            now: function () use (&$clock) {
                return $clock;
            },
        );

        // Failure #1 at t=1000.
        try {
            $manager->connection('main');
        } catch (TransportException) {
        }
        $this->assertSame(1, $attempts);

        // Past that cooldown (t=1006) — succeeds.
        $clock = 1006.0;
        $shouldFail = false;
        $manager->connection('main');
        $this->assertSame(2, $attempts);

        // That connection dies; failure #2 happens at the SAME instant (t=1006).
        $lastClient->close();
        $shouldFail = true;
        try {
            $manager->connection('main');
        } catch (TransportException) {
        }
        $this->assertSame(3, $attempts, 'the earlier success must not block this fresh failure attempt');

        // Only 2s after failure #2 (t=1008) — still within ITS OWN 5s cooldown.
        $clock = 1008.0;
        try {
            $manager->connection('main');
            $this->fail('expected the cooldown from failure #2 (at t=1006) to still be active');
        } catch (TransportException $e) {
            $this->assertStringContainsString('skipping reconnect attempt', $e->getMessage());
        }
        $this->assertSame(3, $attempts, 'connector must not run again during failure #2\'s cooldown window');
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
