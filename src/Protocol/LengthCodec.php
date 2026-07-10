<?php

namespace RouterOS\Sdk\Protocol;

use DomainException;
use OverflowException;
use RouterOS\Sdk\Exceptions\ProtocolException;
use RouterOS\Sdk\Transport\TransportInterface;

/**
 * Encoder/decoder for the RouterOS API "length" field: a variable-length
 * (1 to 5 byte) prefix that precedes every word on the wire.
 *
 * Ported from evilfreelancer/routeros-api-php's APILengthCoDec — the
 * encoding itself (documented at https://wiki.mikrotik.com/wiki/Manual:API#API_words)
 * is unrelated to sync vs. async transport, so the logic carries over as-is;
 * only the source of bytes (TransportInterface instead of StreamInterface)
 * changes.
 */
final class LengthCodec
{
    public static function encodeLength(int $length): string
    {
        if ($length < 0) {
            throw new DomainException("Length of word could not to be negative ($length)");
        }

        if ($length <= 0x7F) {
            return self::packBigEndian($length);
        }

        if ($length <= 0x3FFF) {
            return self::packBigEndian(0x8000 + $length);
        }

        if ($length <= 0x1FFFFF) {
            return self::packBigEndian(0xC00000 + $length);
        }

        if ($length <= 0x0FFFFFFF) {
            return self::packBigEndian(0xE0000000 + $length);
        }

        // https://wiki.mikrotik.com/wiki/Manual:API#API_words
        return self::packBigEndian(0xF000000000 + $length);
    }

    public static function decodeLength(TransportInterface $transport): int
    {
        $firstByte = ord($transport->read(1));

        // 0xxxxxxx — 7-bit length in the first byte
        if (0 === ($firstByte & 0x80)) {
            return $firstByte;
        }

        // 10xxxxxx — 6 bits + 1 byte
        if (0x80 === ($firstByte & 0xC0)) {
            $result = ($firstByte & 0x3F) << 8;
            $result |= ord($transport->read(1));

            return $result;
        }

        // 110xxxxx — 5 bits + 2 bytes
        if (0xC0 === ($firstByte & 0xE0)) {
            $result = ($firstByte & 0x1F) << 16;
            $result |= ord($transport->read(1)) << 8;
            $result |= ord($transport->read(1));

            return $result;
        }

        // 1110xxxx — 4 bits + 3 bytes
        if (0xE0 === ($firstByte & 0xF0)) {
            $result = ($firstByte & 0x0F) << 24;
            $result |= ord($transport->read(1)) << 16;
            $result |= ord($transport->read(1)) << 8;
            $result |= ord($transport->read(1));

            return $result;
        }

        // 11110xxx — 3 bits + 4 bytes
        if (0xF0 === ($firstByte & 0xF8)) {
            if (PHP_INT_SIZE < 8) {
                // @codeCoverageIgnoreStart
                throw new OverflowException("Your system is using 32 bit integers, cannot decode this value ($firstByte)");
                // @codeCoverageIgnoreEnd
            }

            $result = ($firstByte & 0x07) << 32;
            $result |= ord($transport->read(1)) << 24;
            $result |= ord($transport->read(1)) << 16;
            $result |= ord($transport->read(1)) << 8;
            $result |= ord($transport->read(1));

            return $result;
        }

        // 11111xxx — reserved control byte, not used by RouterOS today
        throw new ProtocolException('Control byte found in length prefix (0x' . dechex($firstByte) . ')');
    }

    private static function packBigEndian(int $value): string
    {
        // Emit only as many bytes as the value actually needs (1 to 5),
        // most significant byte first — matches the RouterOS API wire format.
        $bytes = '';
        do {
            $bytes = chr($value & 0xFF) . $bytes;
            $value >>= 8;
        } while ($value > 0);

        return $bytes;
    }
}
