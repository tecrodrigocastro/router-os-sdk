<?php

namespace RouterOS\Sdk\Vpn;

use RouterOS\Sdk\Client;
use RuntimeException;

/**
 * One WireGuard interface (/interface/wireguard) and its peers
 * (/interface/wireguard/peers) — RouterOS 7's native WireGuard support, no
 * separate VPN server product needed for the common "router dials home to
 * a hub" pattern.
 *
 * If you don't already have a keypair, just omit $privateKey from
 * createInterface() — RouterOS generates one itself, same as
 * `/interface/wireguard/add` does with no private-key given. You only need
 * generateKeypair() when you must know the key *before* the router has
 * one (e.g. pre-registering its public key as a peer on a hub elsewhere,
 * or a bootstrap script — see WireGuardBootstrapScript).
 */
final class WireGuard
{
    public function __construct(
        private readonly Client $client,
        private readonly string $interfaceName,
    ) {
    }

    /**
     * @return array<string, string> the interface's row, including the
     *         (auto-generated, if $privateKey was omitted) public-key field
     */
    public function createInterface(int $listenPort = 51820, ?string $privateKey = null): array
    {
        $words = [
            "=name={$this->interfaceName}",
            "=listen-port={$listenPort}",
        ];

        if (null !== $privateKey) {
            $words[] = "=private-key={$privateKey}";
        }

        $this->client->write('/interface/wireguard/add', $words);

        return $this->info() ?? [];
    }

    /** @return array<string, string>|null */
    public function info(): ?array
    {
        return $this->client->findOne('/interface/wireguard', ['name' => $this->interfaceName]);
    }

    public function removeInterface(): bool
    {
        return $this->client->removeWhere('/interface/wireguard', ['name' => $this->interfaceName]) > 0;
    }

    public function addPeer(
        string $publicKey,
        string $allowedAddress,
        ?string $endpointHost = null,
        ?int $endpointPort = null,
        ?string $presharedKey = null,
        ?int $persistentKeepaliveSeconds = null,
        ?string $comment = null,
    ): void {
        $words = [
            "=interface={$this->interfaceName}",
            "=public-key={$publicKey}",
            "=allowed-address={$allowedAddress}",
        ];

        if (null !== $endpointHost) {
            $words[] = "=endpoint-address={$endpointHost}";
        }

        if (null !== $endpointPort) {
            $words[] = "=endpoint-port={$endpointPort}";
        }

        if (null !== $presharedKey) {
            $words[] = "=preshared-key={$presharedKey}";
        }

        if (null !== $persistentKeepaliveSeconds) {
            $words[] = "=persistent-keepalive={$persistentKeepaliveSeconds}s";
        }

        if (null !== $comment) {
            $words[] = "=comment={$comment}";
        }

        $this->client->write('/interface/wireguard/peers/add', $words);
    }

    public function removePeer(string $publicKey): bool
    {
        return $this->client->removeWhere('/interface/wireguard/peers', ['public-key' => $publicKey]) > 0;
    }

    /** @return array<int, array<string, string>> every peer on this interface */
    public function peers(): array
    {
        return $this->client->findWhere('/interface/wireguard/peers', ['interface' => $this->interfaceName]);
    }

    /**
     * Generate a WireGuard-compatible keypair (raw Curve25519/X25519,
     * base64-encoded, same format RouterOS itself uses) — for when you
     * need to know the key before the router has one, e.g. registering it
     * as a peer elsewhere ahead of time, or a bootstrap script. Requires
     * ext-sodium (bundled with PHP but not always enabled by every build).
     *
     * @return array{private: string, public: string}
     */
    public static function generateKeypair(): array
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException(
                'WireGuard::generateKeypair() requires the sodium extension (ext-sodium), which is not loaded. '
                . 'Either enable it, or generate a keypair another way (e.g. the "wg genkey"/"wg pubkey" CLI tools) '
                . 'and pass the keys directly to createInterface()/addPeer().'
            );
        }

        $keypair = sodium_crypto_box_keypair();

        return [
            'private' => base64_encode(sodium_crypto_box_secretkey($keypair)),
            'public'  => base64_encode(sodium_crypto_box_publickey($keypair)),
        ];
    }
}
