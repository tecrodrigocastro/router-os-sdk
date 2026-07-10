<?php

namespace RouterOS\Sdk\Protocol;

use RouterOS\Sdk\Transport\TransportInterface;

/**
 * Reads/writes a single RouterOS API "word" — a LengthCodec-prefixed chunk
 * of bytes. A zero-length word marks the end of a sentence.
 */
final class Word
{
    public static function read(TransportInterface $transport): string
    {
        $length = LengthCodec::decodeLength($transport);

        return $length > 0 ? $transport->read($length) : '';
    }

    public static function write(TransportInterface $transport, string $word): int
    {
        return $transport->write(LengthCodec::encodeLength(strlen($word)) . $word);
    }
}
