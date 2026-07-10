<?php

namespace RouterOS\Sdk\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Client;

/**
 * The only test in the suite that exercises the FULL real stack — Client,
 * Authenticator, Connection, and StreamTransport — over a genuine TCP
 * socket, against a subprocess fake RouterOS server (tests/support/).
 * Everything else in the suite uses FakeTransport for speed/determinism;
 * this one exists to prove those pieces actually interoperate for real.
 */
final class ClientLoopbackTest extends TestCase
{
    /** @var resource|null */
    private $serverProcess;

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
    }

    public function testConnectLoginAndWriteAgainstARealSocket(): void
    {
        $scriptPath = __DIR__ . '/../support/fake-router-server.php';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->serverProcess = proc_open(
            [PHP_BINARY, $scriptPath],
            $descriptors,
            $pipes,
            null,
            null,
        );
        $this->assertIsResource($this->serverProcess);

        $port = trim(fgets($pipes[1]));
        // Deliberately not reading $pipes[2] here for a diagnostic message:
        // on Windows, proc_open pipes can't reliably be made non-blocking,
        // so reading a live stderr pipe blocks until the child exits —
        // exactly long enough to blow past the server's own accept()
        // timeout below and turn a real failure into a confusing one.
        $this->assertMatchesRegularExpression('/^\d+$/', $port, "server did not print a port, got: '$port'");

        $client = Client::connect([
            'host' => '127.0.0.1',
            'port' => (int) $port,
            'user' => 'admin',
            'pass' => 'secret',
            'tls'  => false,
        ]);

        $rows = $client->write('/interface/print');

        $client->close();
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($this->serverProcess);
        $this->serverProcess = null;

        $this->assertSame([['name' => 'ether1', 'running' => 'true']], $rows);
    }
}
