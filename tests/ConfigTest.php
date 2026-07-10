<?php

namespace RouterOS\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Config;
use RouterOS\Sdk\Exceptions\ConfigException;

final class ConfigTest extends TestCase
{
    public function testDefaultPortIsPlainWhenTlsDisabled(): void
    {
        $config = new Config(['host' => '10.0.0.1', 'user' => 'admin', 'pass' => 'secret']);

        $this->assertSame(8728, $config->port());
        $this->assertFalse($config->tls());
    }

    public function testDefaultPortIsTlsWhenTlsEnabled(): void
    {
        $config = new Config(['host' => '10.0.0.1', 'user' => 'admin', 'pass' => 'secret', 'tls' => true]);

        $this->assertSame(8729, $config->port());
    }

    public function testExplicitPortOverridesDefault(): void
    {
        $config = new Config(['host' => '10.0.0.1', 'user' => 'admin', 'pass' => 'secret', 'port' => 1234]);

        $this->assertSame(1234, $config->port());
    }

    public function testMissingRequiredKeyThrows(): void
    {
        $this->expectException(ConfigException::class);
        new Config(['host' => '10.0.0.1', 'user' => 'admin']);
    }

    public function testEmptyRequiredValueThrows(): void
    {
        $this->expectException(ConfigException::class);
        new Config(['host' => '10.0.0.1', 'user' => 'admin', 'pass' => '']);
    }

    public function testUnknownParameterThrowsOnGet(): void
    {
        $config = new Config(['host' => '10.0.0.1', 'user' => 'admin', 'pass' => 'secret']);

        $this->expectException(ConfigException::class);
        $config->get('does_not_exist');
    }

    public function testCommandTimeoutDefault(): void
    {
        $config = new Config(['host' => '10.0.0.1', 'user' => 'admin', 'pass' => 'secret']);

        $this->assertSame(30, $config->get('command_timeout'));
    }
}
