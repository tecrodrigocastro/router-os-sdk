<?php

/**
 * Standalone fake RouterOS server for integration tests: binds an ephemeral
 * loopback port, prints it to stdout, accepts exactly one client, speaks
 * just enough of the real wire protocol (via our own already unit-tested
 * LengthCodec/Word) to answer /login and one /interface/print command,
 * then exits.
 */

require __DIR__ . '/../../vendor/autoload.php';

use RouterOS\Sdk\Protocol\LengthCodec;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\TransportInterface;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
    fwrite(STDERR, "Failed to bind: $errstr\n");
    exit(1);
}

$name = stream_socket_get_name($server, false);
[, $port] = explode(':', $name);

echo $port . "\n";
flush();

$conn = stream_socket_accept($server, 30);
if (false === $conn) {
    fwrite(STDERR, "No client connected within timeout\n");
    exit(1);
}
stream_set_blocking($conn, true);

// Minimal TransportInterface wrapping the accepted socket resource, so the
// server side can reuse our own already unit-tested LengthCodec/Word.
$transport = new class ($conn) implements TransportInterface {
    public function __construct(private $socket)
    {
    }

    public function read(int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($this->socket, $length - strlen($buffer));
            if (false === $chunk || '' === $chunk) {
                throw new RuntimeException('Unexpected EOF from client');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function write(string $data): int
    {
        return fwrite($this->socket, $data);
    }

    public function close(): void
    {
        fclose($this->socket);
    }

    public function isClosed(): bool
    {
        return false;
    }

    public function waitReadable(int $timeoutMs): bool
    {
        return false;
    }
};

/** @return string[] endpoint + attribute words of one client command sentence */
function readCommand(TransportInterface $transport): array
{
    $words = [];
    while ('' !== ($word = Word::read($transport))) {
        $words[] = $word;
    }

    return $words;
}

function writeSentence(TransportInterface $transport, string $type, array $attributeWords, string $tag): void
{
    Word::write($transport, $type);
    foreach ($attributeWords as $word) {
        Word::write($transport, $word);
    }
    Word::write($transport, '.tag=' . $tag);
    Word::write($transport, '');
}

// --- /login ---
$loginWords = readCommand($transport);
if ('/login' !== $loginWords[0]) {
    fwrite(STDERR, "expected /login, got {$loginWords[0]}\n");
    exit(1);
}
$tag = null;
foreach ($loginWords as $w) {
    if (str_starts_with($w, '.tag=')) {
        $tag = substr($w, 5);
    }
}
writeSentence($transport, '!done', [], $tag ?? '1');

// --- /interface/print ---
$printWords = readCommand($transport);
if ('/interface/print' !== $printWords[0]) {
    fwrite(STDERR, "expected /interface/print, got {$printWords[0]}\n");
    exit(1);
}
$tag = null;
foreach ($printWords as $w) {
    if (str_starts_with($w, '.tag=')) {
        $tag = substr($w, 5);
    }
}
$tag ??= '1';
Word::write($transport, '!re');
Word::write($transport, '=name=ether1');
Word::write($transport, '=running=true');
Word::write($transport, '.tag=' . $tag);
Word::write($transport, '');
writeSentence($transport, '!done', [], $tag);

// Don't close our end proactively — an abrupt process exit right after a
// write can race the OS flushing the send buffer and surface as a reset on
// the client side. Instead, block until the CLIENT closes (a read that
// returns EOF), which is what actually happens once Client::close() runs.
@fread($conn, 1);
fclose($conn);
fclose($server);
