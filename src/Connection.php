<?php

namespace RouterOS\Sdk;

use Fiber;
use LogicException;
use RouterOS\Sdk\Io\Reactor;
use RouterOS\Sdk\Protocol\Sentence;
use RouterOS\Sdk\Protocol\SentenceReader;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\StreamTransport;
use RouterOS\Sdk\Transport\TransportInterface;

/**
 * Owns one TCP connection to RouterOS and the tag-multiplexing dispatch
 * table that lets several commands/streams share it concurrently — the
 * capability missing from evilfreelancer/routeros-api-php (strictly one
 * command in flight at a time) and the thing MikroDash's Node ROS class
 * gets from node-routeros's tag-based channels.
 *
 * Connection always assigns its own tag to every command it sends (never
 * trusts a caller-supplied tag) so the dispatch table is the single source
 * of truth for "who owns this tag".
 *
 * Concurrency model: there is exactly one physical reader of the socket at
 * any instant — enforced by the $pumping flag below — because RouterOS
 * serializes all sentences onto one byte stream regardless of how many
 * tags are in flight. Callers that need to wait for their own tag
 * (Future::isSettled() / a Channel's buffer) call waitUntil(): if nobody
 * else is pumping, they pump themselves (reading and dispatching sentences,
 * including ones for OTHER tags, until their own condition is met); if
 * someone else is already pumping, they park on a Fiber::suspend() and are
 * woken after every pump iteration to re-check whether it's their turn.
 *
 * This works transparently for the common single-caller case (no Fiber
 * needed at all — the loop below just runs to completion). Genuine
 * concurrent waiters (e.g. a write() racing an open listen() stream) must
 * each be running inside their own Fiber for the park/resume dance to work;
 * Client wraps calls accordingly (see Client.php).
 */
final class Connection
{
    private TransportInterface $transport;
    private int $nextTag = 1;

    /** @var array<string, TagConsumer> */
    private array $dispatch = [];

    private bool $pumping = false;

    /** @var Fiber[] */
    private array $waiters = [];

    public function __construct(TransportInterface $transport, private readonly int $multiBlockDebounceMs = 20)
    {
        $this->transport = $transport;
    }

    /**
     * @param Reactor|null $reactor When given, the underlying StreamTransport
     *        suspends Fibers instead of blocking the process on socket
     *        reads — required for genuinely concurrent write()/listen()
     *        calls against a real connection. Without one (the default),
     *        this behaves exactly like a plain blocking client — correct
     *        for the common single-caller case. See Reactor's docblock for
     *        who's responsible for driving it (nothing does so automatically).
     */
    public static function connect(Config $config, ?Reactor $reactor = null): self
    {
        $transport = StreamTransport::connect(
            host: $config->host(),
            port: $config->port(),
            tls: $config->tls(),
            connectTimeoutSec: $config->get('connect_timeout'),
            readTimeoutSec: $config->get('read_timeout'),
            sslOptions: $config->tls() ? $config->get('tls_options') : [],
            socketOptions: $config->get('socket_options'),
            reactor: $reactor,
        );

        return new self($transport, $config->get('multi_block_debounce_ms'));
    }

    public function allocateTag(): string
    {
        return (string) $this->nextTag++;
    }

    public function register(string $tag, TagConsumer $consumer): void
    {
        $this->dispatch[$tag] = $consumer;
    }

    public function release(string $tag): void
    {
        unset($this->dispatch[$tag]);
    }

    public function isRegistered(string $tag): bool
    {
        return isset($this->dispatch[$tag]);
    }

    /**
     * Send one sentence: endpoint word, each attribute word, an explicit
     * ".tag=" word (overriding/ignoring any tag baked into $words — tags are
     * Connection's to assign), then the zero-length terminator.
     *
     * @param string[] $words
     */
    public function send(string $endpoint, array $words, string $tag): void
    {
        Word::write($this->transport, $endpoint);
        foreach ($words as $word) {
            if (str_starts_with($word, '.tag=')) {
                continue;
            }
            Word::write($this->transport, $word);
        }
        Word::write($this->transport, '.tag=' . $tag);
        Word::write($this->transport, '');
    }

    /**
     * One-shot command: allocate a tag, send it, block (cooperatively) until
     * its !done/!trap/!empty/!fatal arrives, and return the collected rows.
     *
     * @param string[] $words
     * @return array<int, array<string, string>>
     */
    public function write(string $endpoint, array $words = []): array
    {
        $tag    = $this->allocateTag();
        $future = new Future();
        $this->register($tag, $future);

        $this->send($endpoint, $words, $tag);
        $this->waitUntil(fn () => $future->terminalSeen());

        // Multi-block !done quirk: some devices (wifi-qcom APs) answer a
        // single command with one !re...!done block PER INTERFACE instead
        // of one block for the whole table. Rather than finalizing on the
        // first !done, give RouterOS a short quiet window to send more —
        // if another sentence for this tag arrives inside it, Future just
        // keeps accumulating and terminalSeen() flips again; once the wire
        // has been quiet for the whole window, it's really done.
        while ($this->transport->waitReadable($this->multiBlockDebounceMs)) {
            if ($this->pumping) {
                break; // another Fiber already owns the socket right now
            }
            $this->pumpOnce();
        }

        $future->markSettled();
        $this->release($tag);

        return $future->result();
    }

    public function executeQuery(Query $query): array
    {
        return $this->write($query->getEndpoint(), $query->toWords());
    }

    /**
     * Open an event-driven stream (e.g. "/ip/arp/listen"). Returns
     * immediately with a Channel that fills as RouterOS pushes events;
     * consume it via Channel::onData() or Channel::wait().
     *
     * @param string[] $words
     */
    public function listen(string $endpoint, array $words = []): Channel
    {
        return $this->openChannel($endpoint, $words, Channel::MODE_LISTEN);
    }

    /**
     * Open an "=interval=N" push stream on a print command that has no
     * /listen variant (e.g. "/system/resource/print"). RouterOS resends a
     * full snapshot every $seconds — each cycle is delivered as one payload.
     *
     * @param string[] $words
     */
    public function interval(string $endpoint, int $seconds, array $words = []): Channel
    {
        $words[] = '=interval=' . max(1, $seconds);

        return $this->openChannel($endpoint, $words, Channel::MODE_INTERVAL);
    }

    /** @param string[] $words */
    private function openChannel(string $endpoint, array $words, string $mode): Channel
    {
        $tag     = $this->allocateTag();
        $channel = new Channel($mode);
        $channel->attach($this, $tag);
        $this->register($tag, $channel);

        $this->send($endpoint, $words, $tag);

        return $channel;
    }

    /**
     * Cooperatively pump the socket until $isDone() returns true.
     */
    public function waitUntil(callable $isDone): void
    {
        while (!$isDone()) {
            if (!$this->pumping) {
                $this->pumpOnce();
                continue;
            }

            if (null === Fiber::getCurrent()) {
                throw new LogicException(
                    'Two concurrent Connection operations are contending for the socket outside of Fibers. '
                    . 'Wrap concurrent write()/listen() calls in their own Fiber (see Client).'
                );
            }

            $this->waiters[] = Fiber::getCurrent();
            Fiber::suspend();
        }
    }

    private function pumpOnce(): void
    {
        $this->pumping = true;

        try {
            $sentence = SentenceReader::readSentence($this->transport);
            $this->dispatchSentence($sentence);
        } finally {
            $this->pumping = false;
            $this->wakeWaiters();
        }
    }

    private function dispatchSentence(Sentence $sentence): void
    {
        $tag = $sentence->tag();

        // Unknown/already-released tag — RouterOS quirk parity with
        // node-routeros's UNREGISTEREDTAG handling: discard instead of
        // throwing. Happens for trailing packets after a stream is
        // stopped or a command's tag was already released.
        if (null === $tag || !isset($this->dispatch[$tag])) {
            return;
        }

        if ($this->dispatch[$tag]->deliver($sentence)) {
            unset($this->dispatch[$tag]);
        }
    }

    private function wakeWaiters(): void
    {
        if ([] === $this->waiters) {
            return;
        }

        $waiters       = $this->waiters;
        $this->waiters = [];

        foreach ($waiters as $fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }
    }

    public function close(): void
    {
        $this->transport->close();
    }

    public function isClosed(): bool
    {
        return $this->transport->isClosed();
    }
}
