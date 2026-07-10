<?php

namespace RouterOS\Sdk\Exceptions;

use RuntimeException;

/**
 * RouterOS rejected the /login attempt (wrong username/password).
 */
class BadCredentialsException extends RuntimeException
{
}
