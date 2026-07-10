<?php

namespace RouterOS\Sdk\Tests\Laravel;

use Orchestra\Testbench\TestCase as BaseTestCase;
use RouterOS\Sdk\Integrations\Laravel\Facade;
use RouterOS\Sdk\Integrations\Laravel\ServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['RouterOs' => Facade::class];
    }
}
