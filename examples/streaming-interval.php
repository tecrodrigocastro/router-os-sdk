<?php

/**
 * =interval=N push stream: for RouterOS print commands that have no
 * /listen variant, interval() asks RouterOS itself to push a fresh
 * snapshot every N seconds over the same connection — no polling loop of
 * our own needed.
 *
 *   ROUTEROS_HOST=192.168.88.1 ROUTEROS_USER=admin ROUTEROS_PASS=secret php examples/streaming-interval.php
 */

require __DIR__ . '/_config.php';

use RouterOS\Sdk\Client;

$client = Client::connect(exampleConfig());

echo "Streaming /system/resource/print every 2s (Ctrl+C to stop)...\n";

$resources = $client->interval('/system/resource/print', 2);

while ($cycle = $resources->wait()) {
    $cpu = $cycle[0]['cpu-load'] ?? '?';
    echo '[' . date('H:i:s') . "] cpu-load={$cpu}%\n";
}

$client->close();
