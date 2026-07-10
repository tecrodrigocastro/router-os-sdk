<?php

namespace RouterOS\Sdk\Integrations\Laravel;

use Illuminate\Support\Facades\Facade as BaseFacade;

/**
 * @method static \RouterOS\Sdk\Client connection(?string $name = null)
 * @method static void forgetConnection(?string $name = null)
 * @method static array write(string $endpoint, array $words = [])
 * @method static array query(\RouterOS\Sdk\Query $query)
 * @method static \RouterOS\Sdk\Channel listen(string $endpoint, array $words = [])
 * @method static \RouterOS\Sdk\Channel interval(string $endpoint, int $seconds, array $words = [])
 *
 * @see RouterOsManager
 */
class Facade extends BaseFacade
{
    protected static function getFacadeAccessor(): string
    {
        return RouterOsManager::class;
    }
}
