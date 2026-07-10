<?php

/**
 * The simplest possible example: connect, run a one-shot command, close.
 *
 *   ROUTEROS_HOST=192.168.88.1 ROUTEROS_USER=admin ROUTEROS_PASS=secret php examples/basic-usage.php
 */

require __DIR__ . '/_config.php';

use RouterOS\Sdk\Client;

$client = Client::connect(exampleConfig());

$resources = $client->write('/system/resource/print');
print_r($resources);

$interfaces = $client->write('/interface/print');
echo count($interfaces) . " interface(s) found.\n";

$client->close();
