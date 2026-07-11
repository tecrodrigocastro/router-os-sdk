<?php

namespace RouterOS\Sdk\Tests\Isp;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Isp\RadiusBootstrapScript;

final class RadiusBootstrapScriptTest extends TestCase
{
    public function testGeneratesExpectedRouterOsCommands(): void
    {
        $script = RadiusBootstrapScript::generate(
            radiusAddress: '192.168.88.10',
            secret: 'super-secret',
        );

        $this->assertStringContainsString('/radius', $script);
        $this->assertStringContainsString('add address=192.168.88.10 secret="super-secret" service=ppp', $script);

        $this->assertStringContainsString('/ppp aaa', $script);
        $this->assertStringContainsString('set use-radius=yes accounting=yes', $script);
    }

    public function testAccountingFalseOmitsIt(): void
    {
        $script = RadiusBootstrapScript::generate(
            radiusAddress: '192.168.88.10',
            secret: 'super-secret',
            accounting: false,
        );

        $this->assertStringContainsString('set use-radius=yes accounting=no', $script);
    }

    public function testCustomServiceAndComment(): void
    {
        $script = RadiusBootstrapScript::generate(
            radiusAddress: '192.168.88.10',
            secret: 'super-secret',
            service: 'login',
            comment: 'acme-isp RADIUS bootstrap',
        );

        $this->assertStringContainsString('service=login', $script);
        $this->assertStringContainsString('acme-isp RADIUS bootstrap', $script);
    }

    public function testOutputIsValidRouterOsScriptSyntaxShape(): void
    {
        // Not a real RouterOS parser check - just a sanity net that the
        // heredoc/interpolation didn't produce anything obviously broken
        // (unclosed quotes, stray braces from a botched template).
        $script = RadiusBootstrapScript::generate(
            radiusAddress: '192.168.88.10',
            secret: 'super-secret',
        );

        $this->assertSame(0, substr_count($script, '"') % 2, 'quotes must be balanced (even count)');
        $this->assertGreaterThan(0, substr_count($script, '"'));
        $this->assertSame(0, substr_count($script, '{'));
        $this->assertSame(0, substr_count($script, '}'));
        $this->assertStringStartsWith(':log info', trim($script));
    }
}
