<?php

/**
 * Proves the SDK's core claim against a real router: write() and listen()
 * genuinely run at the same time on one connection, not one after another.
 *
 * Runs /system/resource/print once per second WHILE /ip/arp/listen stays
 * open the whole time, both driven by one Io\Reactor. Connect/disconnect a
 * device on the network to see an ARP event land between two writes.
 *
 *   ROUTEROS_HOST=192.168.88.1 ROUTEROS_USER=admin ROUTEROS_PASS=secret php examples/concurrent-reactor.php
 *
 * Without a Reactor, Client::connect() still lets write()/listen() share a
 * connection correctly (see tests/ConnectionTest.php) but a real blocking
 * socket read would freeze the whole process while waiting for data — the
 * Reactor is what lets a Fiber suspend on that read instead, so another
 * Fiber (here, the periodic write()) can make progress meanwhile.
 */

require __DIR__ . '/_config.php';

use RouterOS\Sdk\Client;
use RouterOS\Sdk\Io\Reactor;

$reactor = new Reactor();
$client  = Client::connect(exampleConfig(), $reactor);

echo "Running write() once/sec + streaming /ip/arp/listen for 20s (Ctrl+C to stop early)...\n";
echo "Connect/disconnect a device on the network to see an ARP event interleave.\n\n";

// Stays parked on the ARP stream for the whole run, in its own Fiber.
$arpFiber = new Fiber(function () use ($client) {
    $arp = $client->listen('/ip/arp/listen');
    while (true) {
        $row = $arp->wait();
        echo '[' . date('H:i:s') . '] ARP event: ';
        print_r($row);
    }
});
$arpFiber->start();

$deadline    = microtime(true) + 20;
$nextWriteAt = microtime(true);
$writeCount  = 0;

while (microtime(true) < $deadline) {
    if (microtime(true) >= $nextWriteAt) {
        $writeCount++;
        $n = $writeCount;

        // Each write() also runs in its own Fiber — this is what lets it
        // suspend on its socket read without blocking the ARP stream above.
        $writeFiber = new Fiber(function () use ($client, $n) {
            $res = $client->write('/system/resource/print');
            $cpu = $res[0]['cpu-load'] ?? '?';
            echo '[' . date('H:i:s') . "] write() #{$n} -> cpu-load={$cpu}%\n";
        });
        $writeFiber->start();
        $nextWriteAt = microtime(true) + 1.0;
    }

    // Nothing drives the Reactor automatically — this loop is that driver,
    // servicing whichever Fiber's socket read becomes ready next.
    $reactor->tick(100);
}

echo "\nDone: {$writeCount} write() calls completed while the ARP listen stream stayed open the whole time.\n";
$client->close();
