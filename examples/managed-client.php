<?php

/**
 * ManagedClient: a supervisor for long-running processes — connects, hands
 * a working Client to onConnected(), and if the connection dies, reconnects
 * with exponential backoff and hands over a fresh one again. The PHP
 * equivalent of MikroDash's Node ROS.connectLoop().
 *
 * Try killing the connection while this runs (unplug the router's cable,
 * or restart it) and watch it back off and recover automatically.
 * Ctrl+C to stop.
 *
 *   ROUTEROS_HOST=192.168.88.1 ROUTEROS_USER=admin ROUTEROS_PASS=secret php examples/managed-client.php
 */

require __DIR__ . '/_config.php';

use RouterOS\Sdk\ManagedClient;

$managed = new ManagedClient(exampleConfig());

$managed->onConnected(function ($client) {
    echo '[' . date('H:i:s') . "] connected — watching /ip/arp/listen\n";

    $arp = $client->listen('/ip/arp/listen');
    while (true) {
        $row = $arp->wait(); // throws when the connection dies, ending this cycle
        echo '[' . date('H:i:s') . '] ARP event: ';
        print_r($row);
    }
});

$managed->onDisconnected(function () {
    echo '[' . date('H:i:s') . "] disconnected — backing off before reconnecting\n";
});

$managed->run();
