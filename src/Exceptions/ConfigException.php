<?php

namespace RouterOS\Sdk\Exceptions;

use InvalidArgumentException;

/**
 * Connection configuration is missing a required key or has an invalid value.
 */
class ConfigException extends InvalidArgumentException
{
}
