<?php

namespace RouterOS\Sdk;

use RouterOS\Sdk\Exceptions\RequestException;
use RouterOS\Sdk\Protocol\Sentence;

/**
 * Collects the response to a single one-shot command (write()) as sentences
 * arrive for its tag.
 *
 * Deliberately never releases its own tag (deliver() always returns false):
 * Connection::write() owns the multi-block !done debounce (some RouterOS
 * devices, e.g. wifi-qcom APs, split one logical response into several
 * !re...!done blocks per interface — see Connection::write()) and decides
 * when a terminal sentence is really final, then calls markSettled().
 */
final class Future implements TagConsumer
{
    /** @var array<int, array<string, string>> */
    private array $rows = [];
    private ?array $trapAttributes = null;
    private bool $terminalSeen = false;
    private bool $settled = false;
    private bool $fatal = false;

    public function deliver(Sentence $sentence): bool
    {
        if ($sentence->isData()) {
            $row = $sentence->attributes;
            unset($row['.tag']);
            $this->rows[] = $row;

            return false;
        }

        if ($sentence->isTrap()) {
            $this->trapAttributes = $sentence->attributes;

            return false;
        }

        if ($sentence->isFatal()) {
            $this->fatal = true;
        }

        // !done / !empty / !fatal — this block of the command is finished,
        // but another block may still follow (multi-block quirk), so don't
        // self-release; Connection decides after its debounce window.
        $this->terminalSeen = true;

        return false;
    }

    public function terminalSeen(): bool
    {
        return $this->terminalSeen;
    }

    /** @internal called by Connection once the debounce window confirms no more blocks are coming */
    public function markSettled(): void
    {
        $this->settled = true;
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }

    /**
     * @return array<int, array<string, string>>
     * @throws RequestException if RouterOS answered with !trap
     */
    public function result(): array
    {
        if ($this->fatal) {
            throw new RequestException($this->trapAttributes ?? [], 'RouterOS closed the connection (!fatal)');
        }

        if (null !== $this->trapAttributes) {
            throw new RequestException($this->trapAttributes);
        }

        return $this->rows;
    }
}
