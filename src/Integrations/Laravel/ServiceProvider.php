<?php

namespace RouterOS\Sdk\Integrations\Laravel;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/router-os.php', 'router-os');

        $this->app->singleton(RouterOsManager::class, function ($app) {
            return new RouterOsManager(
                $app['config']->get('router-os.connections', []),
                $app['config']->get('router-os.default', 'main'),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../../config/router-os.php' => config_path('router-os.php'),
        ], 'config');
    }
}
