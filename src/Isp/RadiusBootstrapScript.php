<?php

namespace RouterOS\Sdk\Isp;

/**
 * Generates a RouterOS ".rsc" script that registers a FreeRADIUS server as
 * the PPP AAA backend on a router — the one-time terminal step every fresh
 * MikroTik needs before RadiusService-style credential provisioning
 * (writing to radcheck/radreply) has any effect, since RouterOS won't
 * consult RADIUS for PPPoE authentication until told to.
 *
 * Pure string templating — no protocol-level code, no connection needed,
 * same shape as Vpn\WireGuardBootstrapScript.
 */
final class RadiusBootstrapScript
{
    public static function generate(
        string $radiusAddress,
        string $secret,
        string $service = 'ppp',
        bool $accounting = true,
        string $comment = 'router-os-sdk RADIUS bootstrap',
    ): string {
        $accountingWord = $accounting ? 'yes' : 'no';

        return <<<RSC
        :log info "{$comment}: starting RADIUS bootstrap..."

        # ── Register the RADIUS server for PPP AAA ───────────────────────
        /radius
        add address={$radiusAddress} secret="{$secret}" service={$service} comment="{$comment}"

        # ── Tell PPP to actually consult RADIUS ───────────────────────────
        /ppp aaa
        set use-radius=yes accounting={$accountingWord}

        :log info "{$comment}: RADIUS bootstrap complete."

        RSC;
    }
}
