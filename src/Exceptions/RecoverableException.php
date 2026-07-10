<?php

namespace RouterOS\Sdk\Exceptions;

/**
 * Marker for exceptions worth retrying after a fresh connection attempt —
 * a transient transport/auth failure, not a programming error. Used by
 * ManagedClient and RouterOsManager to decide what to catch-and-retry vs.
 * let crash immediately: ConfigException/QueryException (a bad connection
 * name, a malformed query) do NOT implement this, since retrying those
 * forever would just hide a real bug instead of recovering from one.
 */
interface RecoverableException
{
}
