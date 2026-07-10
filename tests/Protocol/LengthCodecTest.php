<?php

namespace RouterOS\Sdk\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Protocol\LengthCodec;
use RouterOS\Sdk\Transport\FakeTransport;

final class LengthCodecTest extends TestCase
{
    public static function lengthProvider(): array
    {
        return [
            'zero'                    => [0],
            'one byte max (0x7F)'     => [0x7F],
            'two byte min (0x80)'     => [0x80],
            'two byte max (0x3FFF)'   => [0x3FFF],
            'three byte min (0x4000)' => [0x4000],
            'three byte max (0x1FFFFF)' => [0x1FFFFF],
            'four byte min (0x200000)' => [0x200000],
            'four byte max (0x0FFFFFFF)' => [0x0FFFFFFF],
            'five byte min (0x10000000)' => [0x10000000],
            'realistic word length (12)' => [12],
            'realistic word length (300)' => [300],
        ];
    }

    /** @dataProvider lengthProvider */
    public function testEncodeThenDecodeRoundTrips(int $length): void
    {
        $encoded  = LengthCodec::encodeLength($length);
        $transport = new FakeTransport($encoded);

        $this->assertSame($length, LengthCodec::decodeLength($transport));
        $this->assertSame(0, $transport->pendingReadBytes());
    }

    public function testEncodesSingleByteForShortLength(): void
    {
        $this->assertSame("\x05", LengthCodec::encodeLength(5));
    }

    public function testEncodesTwoBytesAtBoundary(): void
    {
        // 0x80 + (0x8000) = 0x8080
        $this->assertSame("\x80\x80", LengthCodec::encodeLength(0x80));
    }

    public function testNegativeLengthThrows(): void
    {
        $this->expectException(\DomainException::class);
        LengthCodec::encodeLength(-1);
    }
}
