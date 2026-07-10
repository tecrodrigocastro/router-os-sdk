<?php

/**
 * Event-driven stream: listen() opens a RouterOS "/listen" command and
 * pushes one payload per change, for as long as the script runs.
 * Ctrl+C to stop. Try connecting/disconnecting a device on the network
 * while this runs.
 *
 *   ROUTEROS_HOST=192.168.88.1 ROUTEROS_USER=admin ROUTEROS_PASS=secret php examples/streaming-listen.php
 */

require __DIR__ . '/_config.php';

use RouterOS\Sdk\Client;

$client = Client::connect(exampleConfig());

echo "Watching /ip/arp/listen (Ctrl+C to stop)...\n";

$arp = $client->listen('/ip/arp/listen');

while ($row = $arp->wait()) {
    echo '[' . date('H:i:s') . '] ';
    print_r($row);
}

$client->close();
