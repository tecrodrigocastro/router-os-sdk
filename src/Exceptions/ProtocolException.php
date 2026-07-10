<?php

namespace RouterOS\Sdk\Exceptions;

use RuntimeException;

/**
 * Malformed data on the wire: bad length prefix, unknown control byte,
 * or a sentence that doesn't fit the expected shape.
 */
class ProtocolException extends RuntimeException
{
}
