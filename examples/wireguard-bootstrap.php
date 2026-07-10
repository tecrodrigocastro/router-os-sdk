<?php

/**
 * Generates a WireGuard bootstrap .rsc script — needs NO router or network
 * connection at all, it's pure string generation. Print it, paste it into
 * a fresh router's WinBox terminal (or `/import` it), and the router dials
 * home over WireGuard on its own.
 *
 *   php examples/wireguard-bootstrap.php
 *
 * generateKeypair() requires ext-sodium; if it's not available, generate a
 * keypair another way (e.g. the "wg genkey"/"wg pubkey" CLI tools) and pass
 * the keys in directly instead.
 */

require __DIR__ . '/../vendor/autoload.php';

use RouterOS\Sdk\Vpn\WireGuard;
use RouterOS\Sdk\Vpn\WireGuardBootstrapScript;

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "ext-sodium is not loaded — using placeholder keys instead of generating real ones.\n");
    fwrite(STDERR, "Install/enable ext-sodium (or use \"wg genkey\"/\"wg pubkey\") for real keys.\n\n");
    $routerKeys = ['private' => '<router-private-key>', 'public' => '<router-public-key>'];
    $hubKeys    = ['private' => '<hub-private-key>', 'public' => '<hub-public-key>'];
} else {
    // In a real setup: generate the router's keypair here, and separately
    // fetch/generate the hub's own public key (e.g. from your existing
    // WireGuard hub, or its own RouterOS interface via $hub->info()).
    $routerKeys = WireGuard::generateKeypair();
    $hubKeys    = WireGuard::generateKeypair(); // stand-in for "the hub's real public key"
}

$script = WireGuardBootstrapScript::generate(
    interfaceName: 'to-hq',
    listenPort: 51820,
    privateKey: $routerKeys['private'],
    address: '10.200.0.2/24',
    peerPublicKey: $hubKeys['public'],
    peerAllowedAddress: '10.200.0.0/24',
    peerEndpointHost: 'vpn.example.com',
    peerEndpointPort: 51820,
);

echo $script;

fwrite(STDERR, "\n--- Router's public key (register this as a peer on the hub) ---\n");
fwrite(STDERR, $routerKeys['public'] . "\n");
