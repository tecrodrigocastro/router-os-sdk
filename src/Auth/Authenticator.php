<?php

namespace RouterOS\Sdk\Auth;

use RouterOS\Sdk\Config;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Exceptions\BadCredentialsException;
use RouterOS\Sdk\Exceptions\ProtocolException;
use RouterOS\Sdk\Exceptions\RequestException;

/**
 * Performs the RouterOS API /login handshake.
 *
 * Ported from evilfreelancer/routeros-api-php's Client::login(), adapted to
 * Connection::write() instead of the old blocking readRAW(). Supports both:
 *  - modern login (RouterOS >= 6.43): =name=/=password= sent directly
 *  - legacy login (RouterOS < 6.43): an empty /login first, RouterOS
 *    replies with an MD5 challenge salt ("ret"), then a second /login with
 *    =name=/=response= computed from it.
 *
 * Unlike the reference implementation, this does not auto-detect legacy vs.
 * modern and silently retry — the original author's own comments flagged
 * that heuristic as unverified. Callers set Config's 'legacy' flag
 * explicitly; MikroDash-style RouterOS 7.x deployments never need it.
 */
final class Authenticator
{
    public static function login(Connection $connection, Config $config): void
    {
        if ($config->get('legacy')) {
            self::legacyLogin($connection, $config);

            return;
        }

        try {
            $connection->write('/login', [
                '=name=' . $config->user(),
                '=password=' . $config->pass(),
            ]);
        } catch (RequestException $e) {
            throw new BadCredentialsException('Invalid user name or password', 0, $e);
        }
    }

    private static function legacyLogin(Connection $connection, Config $config): void
    {
        $rows = $connection->write('/login');
        $challenge = $rows[0]['ret'] ?? null;

        if (!is_string($challenge) || '' === $challenge) {
            throw new ProtocolException('Legacy /login did not return a challenge ("ret")');
        }

        $response = '00' . md5(chr(0) . $config->pass() . pack('H*', $challenge));

        try {
            $connection->write('/login', [
                '=name=' . $config->user(),
                '=response=' . $response,
            ]);
        } catch (RequestException $e) {
            throw new BadCredentialsException('Invalid user name or password', 0, $e);
        }
    }
}
