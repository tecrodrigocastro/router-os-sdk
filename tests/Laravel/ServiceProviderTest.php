<?php

namespace RouterOS\Sdk\Tests\Laravel;

use RouterOS\Sdk\Integrations\Laravel\RouterOsManager;
use RouterOS\Sdk\Integrations\Laravel\ServiceProvider;

final class ServiceProviderTest extends TestCase
{
    public function testDefaultConfigIsMerged(): void
    {
        // No config/router-os.php set up in the test app — the package's
        // own default (config/router-os.php) must still be merged in via
        // mergeConfigFrom(), exactly like publishing a real Laravel config.
        $this->assertSame('main', $this->app['config']->get('router-os.default'));
        $this->assertIsArray($this->app['config']->get('router-os.connections.main'));
        $this->assertArrayHasKey('host', $this->app['config']->get('router-os.connections.main'));
    }

    public function testUserConfigOverridesDefault(): void
    {
        $this->app['config']->set('router-os.connections.main.host', '10.0.0.1');

        $config = $this->app['config']->get('router-os.connections.main');

        $this->assertSame('10.0.0.1', $config['host']);
    }

    public function testManagerIsBoundAsSingleton(): void
    {
        $first  = $this->app->make(RouterOsManager::class);
        $second = $this->app->make(RouterOsManager::class);

        $this->assertInstanceOf(RouterOsManager::class, $first);
        $this->assertSame($first, $second);
    }

    public function testConfigCanBePublished(): void
    {
        $provider = new ServiceProvider($this->app);

        $paths = $provider::pathsToPublish(ServiceProvider::class, 'config');

        $this->assertNotEmpty($paths);
        $this->assertStringEndsWith('router-os.php', array_values($paths)[0]);
    }
}
