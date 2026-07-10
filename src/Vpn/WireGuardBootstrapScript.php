<?php

namespace RouterOS\Sdk\Vpn;

/**
 * Generates a RouterOS ".rsc" script that bootstraps a WireGuard tunnel on
 * a router with no connectivity yet — the chicken-and-egg problem any
 * "router dials home to a central hub" setup has: the SDK can't configure
 * the router live over an API it can't reach until the tunnel exists. A
 * field technician pastes this into the router's terminal (or `/import`s
 * it) once, on-site, over the LAN.
 *
 * Pure string templating — no protocol-level code, no connection needed.
 * Pair with WireGuard::generateKeypair() to get $privateKey/$peerPublicKey
 * ahead of time so the hub side can register the peer before the script
 * ever runs.
 */
final class WireGuardBootstrapScript
{
    public static function generate(
        string $interfaceName,
        int $listenPort,
        string $privateKey,
        string $address,
        string $peerPublicKey,
        string $peerAllowedAddress,
        ?string $peerEndpointHost = null,
        ?int $peerEndpointPort = null,
        int $apiPort = 8728,
        string $comment = 'router-os-sdk bootstrap',
    ): string {
        $peerEndpointWords = '';
        if (null !== $peerEndpointHost) {
            $peerEndpointWords .= " endpoint-address={$peerEndpointHost}";
        }
        if (null !== $peerEndpointPort) {
            $peerEndpointWords .= " endpoint-port={$peerEndpointPort}";
        }

        return <<<RSC
        :log info "{$comment}: starting WireGuard bootstrap..."

        # ── WireGuard interface ──────────────────────────────────────────
        /interface wireguard
        add name={$interfaceName} listen-port={$listenPort} private-key="{$privateKey}" comment="{$comment}"

        # ── Tunnel address ───────────────────────────────────────────────
        /ip address
        add address={$address} interface={$interfaceName} comment="{$comment}"

        # ── Peer (the hub this router dials home to) ─────────────────────
        /interface wireguard peers
        add interface={$interfaceName} public-key="{$peerPublicKey}" allowed-address={$peerAllowedAddress}{$peerEndpointWords} persistent-keepalive=25s comment="{$comment}"

        # ── Firewall: only accept the API over the tunnel ────────────────
        /ip firewall filter
        add chain=input protocol=tcp dst-port={$apiPort} in-interface={$interfaceName} action=accept place-before=0 comment="{$comment}: API over WireGuard"

        # ── API enabled, restricted to the tunnel's allowed range ────────
        /ip service
        set api address={$peerAllowedAddress} disabled=no

        :log info "{$comment}: WireGuard bootstrap complete."

        RSC;
    }
}
