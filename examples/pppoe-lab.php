<?php

/**
 * End-to-end PPPoE lab test: creates a customer secret on the RouterOS
 * PPPoE server, waits for you to point a real CPE at it (e.g. an Intelbras
 * home router with WAN set to PPPoE), then walks through
 * suspend()/activate() so you can watch the session drop and come back.
 *
 * Assumes the PPPoE server itself is already configured on the router (pool,
 * profile, /interface pppoe-server server) — see docs/router-hardware-setup.md
 * section 7. This script only does what the SDK is actually for: creating
 * and managing a per-customer secret.
 *
 *   ROUTEROS_HOST=192.168.88.1 ROUTEROS_USER=admin ROUTEROS_PASS=secret php examples/pppoe-lab.php
 *
 * Pass --keep to skip the final cleanup (leaves the secret in place).
 */

require __DIR__ . '/_config.php';

use RouterOS\Sdk\Client;

$username = 'cliente-teste';
$password = 'senha-teste-123';
$profile  = 'perfil-cliente';
$keep     = in_array('--keep', $argv, true);

$client = Client::connect(exampleConfig());

echo "--- Creating PPP secret ---\n";
$existing = $client->pppSecrets()->find($username);
if (null !== $existing) {
    echo "Secret '{$username}' already exists, reusing it.\n";
} else {
    $client->pppSecrets()->create($username, $password, profile: $profile);
    echo "Created secret '{$username}' / '{$password}' (profile={$profile}).\n";
}

echo "\nNow configure your CPE's WAN as PPPoE with that username/password.\n";
echo "Press Enter once it's dialed in (check WinBox > PPP > Active first if unsure)...";
fgets(STDIN);

if ($client->pppSecrets()->isOnline($username)) {
    $session = $client->pppSecrets()->activeSessions($username)[0];
    echo "Online! address=" . ($session['address'] ?? '?') . " uptime=" . ($session['uptime'] ?? '?') . "\n";
} else {
    echo "Not online yet — check /ppp active print and /log print on the router for auth errors.\n";
}

echo "\n--- Suspending (disable + kill) ---\n";
$result = $client->customer($username)->suspend(pppUser: $username);
echo "succeeded: " . implode(', ', $result->succeeded) . "\n";
foreach ($result->failed as $action => $message) {
    echo "failed: {$action} -> {$message}\n";
}

echo "\nThe CPE should now be stuck retrying (auth failure). Press Enter to reactivate...";
fgets(STDIN);

echo "\n--- Activating ---\n";
$result = $client->customer($username)->activate(pppUser: $username);
echo "succeeded: " . implode(', ', $result->succeeded) . "\n";
foreach ($result->failed as $action => $message) {
    echo "failed: {$action} -> {$message}\n";
}

echo "\nThe CPE should reconnect on its own shortly (PPPoE clients retry automatically).\n";

if (!$keep) {
    echo "\n--- Cleaning up ---\n";
    $client->pppSecrets()->remove($username);
    echo "Removed secret '{$username}'. Run with --keep to leave it in place next time.\n";
}

$client->close();
