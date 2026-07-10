<?php

namespace RouterOS\Sdk\Tests\Vpn;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Vpn\WireGuardBootstrapScript;

final class WireGuardBootstrapScriptTest extends TestCase
{
    public function testGeneratesExpectedRouterOsCommands(): void
    {
        $script = WireGuardBootstrapScript::generate(
            interfaceName: 'to-hq',
            listenPort: 51820,
            privateKey: 'privkey123=',
            address: '10.200.0.2/24',
            peerPublicKey: 'hubpubkey456=',
            peerAllowedAddress: '10.200.0.0/24',
            peerEndpointHost: 'vpn.example.com',
            peerEndpointPort: 51820,
        );

        $this->assertStringContainsString('/interface wireguard', $script);
        $this->assertStringContainsString('add name=to-hq listen-port=51820 private-key="privkey123="', $script);

        $this->assertStringContainsString('/ip address', $script);
        $this->assertStringContainsString('add address=10.200.0.2/24 interface=to-hq', $script);

        $this->assertStringContainsString('/interface wireguard peers', $script);
        $this->assertStringContainsString('public-key="hubpubkey456="', $script);
        $this->assertStringContainsString('allowed-address=10.200.0.0/24', $script);
        $this->assertStringContainsString('endpoint-address=vpn.example.com', $script);
        $this->assertStringContainsString('endpoint-port=51820', $script);

        $this->assertStringContainsString('/ip firewall filter', $script);
        $this->assertStringContainsString('dst-port=8728', $script);
        $this->assertStringContainsString('in-interface=to-hq', $script);

        $this->assertStringContainsString('/ip service', $script);
        $this->assertStringContainsString('set api address=10.200.0.0/24 disabled=no', $script);
    }

    public function testOmittingEndpointLeavesThoseWordsOut(): void
    {
        $script = WireGuardBootstrapScript::generate(
            interfaceName: 'to-hq',
            listenPort: 51820,
            privateKey: 'privkey123=',
            address: '10.200.0.2/24',
            peerPublicKey: 'hubpubkey456=',
            peerAllowedAddress: '10.200.0.0/24',
        );

        $this->assertStringNotContainsString('endpoint-address=', $script);
        $this->assertStringNotContainsString('endpoint-port=', $script);
    }

    public function testCustomApiPortAndComment(): void
    {
        $script = WireGuardBootstrapScript::generate(
            interfaceName: 'to-hq',
            listenPort: 51820,
            privateKey: 'privkey123=',
            address: '10.200.0.2/24',
            peerPublicKey: 'hubpubkey456=',
            peerAllowedAddress: '10.200.0.0/24',
            apiPort: 8729,
            comment: 'acme-isp bootstrap',
        );

        $this->assertStringContainsString('dst-port=8729', $script);
        $this->assertStringContainsString('acme-isp bootstrap', $script);
    }

    public function testOutputIsValidRouterOsScriptSyntaxShape(): void
    {
        // Not a real RouterOS parser check - just a sanity net that the
        // heredoc/interpolation didn't produce anything obviously broken
        // (unclosed quotes, stray braces from a botched template).
        $script = WireGuardBootstrapScript::generate(
            interfaceName: 'to-hq',
            listenPort: 51820,
            privateKey: 'privkey123=',
            address: '10.200.0.2/24',
            peerPublicKey: 'hubpubkey456=',
            peerAllowedAddress: '10.200.0.0/24',
        );

        $this->assertSame(0, substr_count($script, '"') % 2, 'quotes must be balanced (even count)');
        $this->assertGreaterThan(0, substr_count($script, '"'));
        $this->assertSame(0, substr_count($script, '{'));
        $this->assertSame(0, substr_count($script, '}'));
        $this->assertStringStartsWith(':log info', trim($script));
    }
}
