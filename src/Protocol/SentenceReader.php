<?php

namespace RouterOS\Sdk\Protocol;

use RouterOS\Sdk\Exceptions\ProtocolException;
use RouterOS\Sdk\Transport\TransportInterface;

/**
 * Reads one full sentence (type word + attribute words, up to the
 * zero-length terminator) off a transport.
 *
 * This class only parses bytes into a Sentence — it has no notion of which
 * tags are currently registered. Dropping sentences for tags nobody is
 * listening on anymore (the RouterOS "trailing packet after !done/stream
 * stop" case node-routeros calls UNREGISTEREDTAG) is a dispatch concern and
 * belongs to Connection, which is the only place that knows the tag table.
 */
final class SentenceReader
{
    private const TYPES = [
        Sentence::TYPE_RE,
        Sentence::TYPE_DONE,
        Sentence::TYPE_TRAP,
        Sentence::TYPE_FATAL,
        Sentence::TYPE_EMPTY,
    ];

    public static function readSentence(TransportInterface $transport): Sentence
    {
        $type = Word::read($transport);

        if (!in_array($type, self::TYPES, true)) {
            throw new ProtocolException('Expected a sentence type word, got: ' . var_export($type, true));
        }

        $attributes = [];

        while ('' !== ($word = Word::read($transport))) {
            $parsed = self::parseAttributeWord($word);
            if (null !== $parsed) {
                [$key, $value] = $parsed;
                $attributes[$key] = $value;
            }
        }

        return new Sentence($type, $attributes);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function parseAttributeWord(string $word): ?array
    {
        // Two word shapes on the wire, distinguished by their leading char:
        //   ".tag=7"              — a control word; key keeps its leading dot (".tag")
        //   "=name=ether1"        — a regular attribute; key is "name"
        //   "=.proplist=a,b,c"    — a regular attribute whose *name* itself
        //                           starts with a dot; key keeps it (".proplist")
        // Either way, the value is everything after the key's own '=' and may
        // itself contain '=' (e.g. "=comment=a=b"), so split on the first
        // remaining '=' only.
        if ('' === $word) {
            return null;
        }

        if ('.' === $word[0]) {
            $eqPos = strpos($word, '=');
            if (false === $eqPos) {
                return null;
            }

            return [substr($word, 0, $eqPos), substr($word, $eqPos + 1)];
        }

        if ('=' === $word[0]) {
            $rest  = substr($word, 1);
            $eqPos = strpos($rest, '=');
            if (false === $eqPos) {
                return null;
            }

            return [substr($rest, 0, $eqPos), substr($rest, $eqPos + 1)];
        }

        return null;
    }
}
