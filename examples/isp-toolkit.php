<?php

/**
 * ISP toolkit (RouterOS\Sdk\Isp\*): the common "provision/suspend a
 * customer" operations for a PPPoE-based ISP, on top of the generic
 * write()/findWhere()/removeWhere()/setWhere() primitives.
 *
 * CAUTION: this actually creates/removes a PPP secret and a firewall
 * address-list entry on the connected router. Point ROUTEROS_HOST at a lab
 * router, not production, before running this.
 *
 *   ROUTEROS_HOST=192.168.88.1 ROUTEROS_USER=admin ROUTEROS_PASS=secret php examples/isp-toolkit.php
 */

require __DIR__ . '/_config.php';

use RouterOS\Sdk\Client;

$client = Client::connect(exampleConfig());

$username = 'demo-customer';

echo "--- PPPoE secret ---\n";
$client->pppSecrets()->create($username, 'senha-de-teste', profile: 'default');
echo "Created secret '{$username}'.\n";

$found = $client->pppSecrets()->find($username);
echo "find() -> ";
print_r($found);

echo $client->pppSecrets()->isOnline($username) ? "Customer is online.\n" : "Customer is offline (expected — nothing actually dialed in).\n";

echo "--- Address-list (delinquency block) ---\n";
$list = $client->addressList('demo-morosos');
$blocked = $list->block('203.0.113.5', comment: 'Demo — safe to remove');
echo $blocked ? "Blocked 203.0.113.5.\n" : "Already blocked (idempotent — no duplicate entry created).\n";
echo 'isBlocked() -> ' . ($list->isBlocked('203.0.113.5') ? 'true' : 'false') . "\n";

echo "--- Simple queue (bandwidth) ---\n";
$client->simpleQueue()->create("queue-{$username}", '203.0.113.5/32', '20M/20M');
echo "Created a 20M/20M queue.\n";

echo "\n--- Cleaning up everything this script created ---\n";
$client->pppSecrets()->remove($username);
$list->unblock('203.0.113.5');
$client->simpleQueue()->remove("queue-{$username}");
echo "Done.\n";

$client->close();
