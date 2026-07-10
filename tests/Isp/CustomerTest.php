<?php

namespace RouterOS\Sdk\Tests\Isp;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class CustomerTest extends TestCase
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

    public function testSuspendRunsAllThreeActionsAndReportsFullSuccess(): void
    {
        $transport = new FakeTransport();
        $client = Client::fromConnection(new Connection($transport));
        $customer = $client->customer('joao');

        $result = null;
        $fiber = new \Fiber(function () use ($customer, &$result) {
            $result = $customer->suspend(address: '10.0.0.5', pppUser: 'joao', queueName: 'queue-joao');
        });
        $fiber->start();

        // address_list: isBlocked() check (tag 1) -> not blocked -> /add (tag 2)
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        // ppp secret: disable (find tag3 + set tag4), kill (find tag5 + remove tag6)
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!re', ['=.id=*1', '=name=joao', '.tag=3']) . $this->sentenceBytes('!done', ['.tag=3']));
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=4']));
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!re', ['=.id=*2', '=name=joao', '.tag=5']) . $this->sentenceBytes('!done', ['.tag=5']));
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=6']));

        // queue: disable (find tag7 + set tag8)
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!re', ['=.id=*3', '=name=queue-joao', '.tag=7']) . $this->sentenceBytes('!done', ['.tag=7']));
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=8']));

        $this->assertTrue($fiber->isTerminated());
        $this->assertTrue($result->isFullSuccess());
        $this->assertSame(['address_list', 'ppp_disabled', 'queue_disabled'], $result->succeeded);
        $this->assertSame([], $result->failed);
    }

    public function testSuspendIsolatesAFailingActionFromTheOthers(): void
    {
        $transport = new FakeTransport();
        $client = Client::fromConnection(new Connection($transport));
        $customer = $client->customer('joao');

        $result = null;
        $fiber = new \Fiber(function () use ($customer, &$result) {
            $result = $customer->suspend(address: '10.0.0.5', pppUser: null, queueName: 'ghost-queue');
        });
        $fiber->start();

        // address_list: isBlocked() -> not blocked -> /add succeeds
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!empty', ['.tag=1']));
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        // queue: disable() -> find returns nothing -> setWhere does nothing,
        // returns 0 -> disable() returns false (no exception) - simulate
        // instead a genuine failure via !trap so the isolation is real.
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead(
            $this->sentenceBytes('!trap', ['=message=no such queue', '.tag=3'])
            . $this->sentenceBytes('!done', ['.tag=3'])
        );

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame(['address_list'], $result->succeeded);
        $this->assertArrayHasKey('queue_disabled', $result->failed);
        $this->assertSame('no such queue', $result->failed['queue_disabled']);
        $this->assertFalse($result->isFullSuccess());
        $this->assertFalse($result->isTotalFailure());
    }

    public function testActivateUnblocksEnablesPppAndQueue(): void
    {
        $transport = new FakeTransport();
        $client = Client::fromConnection(new Connection($transport));
        $customer = $client->customer('joao');

        $result = null;
        $fiber = new \Fiber(function () use ($customer, &$result) {
            $result = $customer->activate(address: '10.0.0.5', pppUser: 'joao', queueName: null);
        });
        $fiber->start();

        // address_list unblock: find (tag1) -> matches -> /remove (tag2)
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*9', '=address=10.0.0.5', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));

        // ppp enable: find (tag3) -> matches -> /set (tag4)
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=.id=*1', '=name=joao', '.tag=3'])
            . $this->sentenceBytes('!done', ['.tag=3'])
        );
        $this->assertTrue($fiber->isSuspended());
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=4']));

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame(['address_list', 'ppp_enabled'], $result->succeeded);
        $this->assertSame([], $result->failed);
    }
}
