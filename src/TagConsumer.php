<?php

namespace RouterOS\Sdk;

use RouterOS\Sdk\Protocol\Sentence;

/**
 * Whatever owns a tag in Connection's dispatch table — a Future (one-shot
 * command) or a Channel (long-lived /listen or =interval=N stream).
 */
interface TagConsumer
{
    /**
     * Feed one sentence addressed to this consumer's tag.
     *
     * @return bool true if the tag should be released (no more sentences
     *              expected — e.g. a one-shot command just got its !done),
     *              false to keep the tag registered for more.
     */
    public function deliver(Sentence $sentence): bool;
}
