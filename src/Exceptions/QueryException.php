<?php

namespace RouterOS\Sdk\Exceptions;

use InvalidArgumentException;

/**
 * A Query was built incorrectly (bad operator, empty endpoint, ...).
 */
class QueryException extends InvalidArgumentException
{
}
