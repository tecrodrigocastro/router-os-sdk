<?php

namespace RouterOS\Sdk\Exceptions;

use RuntimeException;

/**
 * RouterOS rejected the /login attempt (wrong username/password).
 *
 * Implements RecoverableException so ManagedClient/RouterOsManager retry
 * with backoff on this too, same as a transport failure — this matches
 * MikroDash's own connectLoop(), which doesn't distinguish "wrong password"
 * from "network blip" and just keeps retrying, showing "disconnected"
 * either way. It's a deliberate choice, not an oversight: retrying forever
 * on a genuinely wrong password is somewhat wasteful, but the alternative
 * (crash the whole daemon) is worse for a long-running process.
 */
class BadCredentialsException extends RuntimeException implements RecoverableException
{
}
