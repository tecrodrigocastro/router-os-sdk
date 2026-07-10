<?php

namespace RouterOS\Sdk;

use RouterOS\Sdk\Exceptions\ConfigException;

/**
 * Typed, validated connection configuration.
 *
 * Trimmed down from evilfreelancer/routeros-api-php's Config.php: keeps the
 * validated parameter-bag approach but drops SSH/export-related options
 * (out of scope for this SDK) and renames 'ssl' to 'tls' to match
 * MikroDash's naming (RouterOS API-over-TLS is what port 8729 actually is).
 */
final class Config
{
    private const DEFAULTS = [
        'port'                => null, // null => 8729 if tls, else 8728
        'tls'                 => false,
        'tls_options'         => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
        'socket_options'      => [],
        'legacy'              => false,
        'connect_timeout'     => 10,
        'read_timeout'        => 30,
        'write_timeout'       => 30,
        'attempts'            => 1,
        'attempts_delay'      => 1,
        // How long (seconds) a one-shot command may wait for its !done/!trap
        // before Connection gives up and rejects its Future.
        'command_timeout'     => 30,
        // Debounce window (ms) Connection waits after a !done for more
        // multi-block data before resolving a one-shot command — needed for
        // devices (e.g. wifi-qcom APs) that send one !re/!done block per
        // interface instead of a single block for the whole table.
        'multi_block_debounce_ms' => 20,
    ];

    private const REQUIRED = ['host', 'user', 'pass'];

    /** @var array<string, mixed> */
    private array $parameters;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(array $parameters)
    {
        foreach (self::REQUIRED as $key) {
            if (!array_key_exists($key, $parameters) || '' === $parameters[$key]) {
                throw new ConfigException("Required config parameter '$key' is missing or empty");
            }
        }

        $this->parameters = array_merge(self::DEFAULTS, $parameters);
    }

    public function get(string $key): mixed
    {
        if ('port' === $key && null === $this->parameters['port']) {
            return $this->parameters['tls'] ? 8729 : 8728;
        }

        if (!array_key_exists($key, $this->parameters)) {
            throw new ConfigException("Unknown config parameter '$key'");
        }

        return $this->parameters[$key];
    }

    public function host(): string
    {
        return $this->parameters['host'];
    }

    public function user(): string
    {
        return $this->parameters['user'];
    }

    public function pass(): string
    {
        return $this->parameters['pass'];
    }

    public function port(): int
    {
        return $this->get('port');
    }

    public function tls(): bool
    {
        return (bool) $this->parameters['tls'];
    }
}
