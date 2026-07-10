<?php

namespace RouterOS\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Exceptions\TransportException;
use RouterOS\Sdk\ManagedClient;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class ManagedClientTest extends TestCase
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

    /** @return callable(int): void */
    private function recordingSleeper(array &$sleeps): callable
    {
        return function (int $ms) use (&$sleeps) {
            $sleeps[] = $ms;
        };
    }

    public function testOnConnectedFiresWithAWorkingClientThenStops(): void
    {
        $transport = new FakeTransport();
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=name=ether1', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $received = null;
        $selfSeen = null;

        $managed = new ManagedClient(
            config: ['host' => 'x', 'user' => 'y', 'pass' => 'z'],
            connector: fn () => Client::fromConnection(new Connection($transport)),
            sleeper: function (int $ms) {
                $this->fail('should never sleep when the very first cycle ends via stop()');
            },
        );

        $managed->onConnected(function (Client $client, ManagedClient $self) use (&$received, &$selfSeen, $managed) {
            $received = $client->write('/interface/print');
            $selfSeen = $self;
            $managed->stop();
        });

        $managed->run();

        $this->assertSame([['name' => 'ether1']], $received);
        $this->assertSame($managed, $selfSeen);
    }

    public function testCallbackFailureTriggersReconnectAndOnDisconnected(): void
    {
        $attempts        = 0;
        $disconnectCount = 0;
        $connectedCount  = 0;
        $sleeps          = [];

        $managed = new ManagedClient(
            config: ['host' => 'x', 'user' => 'y', 'pass' => 'z'],
            initialBackoffMs: 50,
            maxBackoffMs: 1000,
            connector: function () use (&$attempts) {
                $attempts++;

                return Client::fromConnection(new Connection(new FakeTransport()));
            },
            sleeper: $this->recordingSleeper($sleeps),
        );

        $managed->onConnected(function (Client $client) use (&$connectedCount, $managed) {
            $connectedCount++;
            if (1 === $connectedCount) {
                // Simulate the connection dying while this callback's own
                // consumer loop was running.
                throw new TransportException('simulated drop');
            }

            // Second cycle: stop gracefully instead of throwing.
            $managed->stop();
        });

        $managed->onDisconnected(function () use (&$disconnectCount) {
            $disconnectCount++;
        });

        $managed->run();

        $this->assertSame(2, $attempts, 'expected exactly one reconnect after the simulated drop');
        $this->assertSame(2, $connectedCount);
        $this->assertSame(2, $disconnectCount, 'onDisconnected should fire once per ended cycle, including the graceful stop');
        $this->assertSame([50], $sleeps, 'only one backoff sleep, between the failed cycle and the reconnect');
    }

    public function testBackoffDoublesOnRepeatedConnectFailuresUpToTheCap(): void
    {
        $sleeps       = [];
        $attempts     = 0;
        $managedSlot  = ['instance' => null];

        $connector = function () use (&$attempts, &$managedSlot) {
            $attempts++;
            if ($attempts >= 4) {
                $managedSlot['instance']->stop();
            }

            throw new TransportException('still down');
        };

        $managed = new ManagedClient(
            config: ['host' => 'x', 'user' => 'y', 'pass' => 'z'],
            initialBackoffMs: 100,
            maxBackoffMs: 300,
            connector: $connector,
            sleeper: $this->recordingSleeper($sleeps),
        );
        $managedSlot['instance'] = $managed;

        $managed->run();

        $this->assertSame(4, $attempts);
        $this->assertSame([100, 200, 300], $sleeps, 'backoff must double each failure and cap at maxBackoffMs');
    }

    public function testIsStoppingIsVisibleToCallbacks(): void
    {
        $observed = null;

        $managed = new ManagedClient(
            config: ['host' => 'x', 'user' => 'y', 'pass' => 'z'],
            connector: fn () => Client::fromConnection(new Connection(new FakeTransport())),
        );

        $managed->onConnected(function (Client $client, ManagedClient $self) use (&$observed, $managed) {
            $observed = $self->isStopping();
            $managed->stop();
        });

        $managed->run();

        $this->assertFalse($observed, 'must not report stopping before stop() was called');
        $this->assertTrue($managed->isStopping());
    }
}
