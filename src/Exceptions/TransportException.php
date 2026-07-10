<?php

namespace RouterOS\Sdk\Exceptions;

use RuntimeException;

/**
 * The underlying socket/stream failed or was closed while data was expected.
 */
class TransportException extends RuntimeException
{
}
