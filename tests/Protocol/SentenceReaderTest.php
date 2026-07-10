<?php

namespace RouterOS\Sdk\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Exceptions\ProtocolException;
use RouterOS\Sdk\Protocol\Sentence;
use RouterOS\Sdk\Protocol\SentenceReader;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class SentenceReaderTest extends TestCase
{
    /**
     * Builds the raw wire bytes for a sentence: type word + attribute words
     * + zero-length terminator, exactly as RouterOS would send them.
     */
    private function encodeSentence(string $type, array $words = []): string
    {
        $writer = new FakeTransport();
        Word::write($writer, $type);
        foreach ($words as $word) {
            Word::write($writer, $word);
        }
        Word::write($writer, ''); // terminator

        return implode('', $writer->writtenLog());
    }

    public function testParsesReWithAttributes(): void
    {
        $bytes = $this->encodeSentence('!re', ['=name=ether1', '=running=true', '.tag=7']);
        $sentence = SentenceReader::readSentence(new FakeTransport($bytes));

        $this->assertTrue($sentence->isData());
        $this->assertSame('7', $sentence->tag());
        $this->assertSame(['name' => 'ether1', 'running' => 'true', '.tag' => '7'], $sentence->attributes);
    }

    public function testParsesPlainDoneWithNoAttributes(): void
    {
        $bytes = $this->encodeSentence('!done');
        $sentence = SentenceReader::readSentence(new FakeTransport($bytes));

        $this->assertTrue($sentence->isTerminal());
        $this->assertSame([], $sentence->attributes);
    }

    public function testEmptyIsTreatedAsTerminal(): void
    {
        // RouterOS 7.18+ quirk: !empty replaces !done when a command
        // matched zero rows. Must be terminal, not an unknown reply.
        $bytes = $this->encodeSentence('!empty');
        $sentence = SentenceReader::readSentence(new FakeTransport($bytes));

        $this->assertSame(Sentence::TYPE_EMPTY, $sentence->type);
        $this->assertTrue($sentence->isTerminal());
    }

    public function testTrapIsNotTerminalButIsTrap(): void
    {
        $bytes = $this->encodeSentence('!trap', ['=message=failure: already have such address']);
        $sentence = SentenceReader::readSentence(new FakeTransport($bytes));

        $this->assertTrue($sentence->isTrap());
        $this->assertFalse($sentence->isTerminal());
        $this->assertSame('failure: already have such address', $sentence->attributes['message']);
    }

    public function testDotPrefixedAttributeKeyIsPreserved(): void
    {
        $bytes = $this->encodeSentence('!re', ['=.proplist=name,running', '.tag=3']);
        $sentence = SentenceReader::readSentence(new FakeTransport($bytes));

        $this->assertSame('name,running', $sentence->attributes['.proplist']);
    }

    public function testValueContainingEqualsSignIsKeptWhole(): void
    {
        $bytes = $this->encodeSentence('!re', ['=comment=a=b=c']);
        $sentence = SentenceReader::readSentence(new FakeTransport($bytes));

        $this->assertSame('a=b=c', $sentence->attributes['comment']);
    }

    public function testUnrecognisedTypeWordThrows(): void
    {
        $bytes = $this->encodeSentence('!bogus');

        $this->expectException(ProtocolException::class);
        SentenceReader::readSentence(new FakeTransport($bytes));
    }
}
