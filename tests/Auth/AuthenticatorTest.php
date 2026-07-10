<?php

namespace RouterOS\Sdk\Tests\Auth;

use Fiber;
use PHPUnit\Framework\TestCase;
use RouterOS\Sdk\Auth\Authenticator;
use RouterOS\Sdk\Config;
use RouterOS\Sdk\Connection;
use RouterOS\Sdk\Exceptions\BadCredentialsException;
use RouterOS\Sdk\Protocol\Word;
use RouterOS\Sdk\Transport\FakeTransport;

final class AuthenticatorTest extends TestCase
{
    private function sentenceBytes(string $type, array $words = []): string
    {
        $writer = new FakeTransport();
        Word::write($writer, $type);
        foreach ($words as $word) {
            Word::write($writer, $word);
        }
        Word::write($writer, '');

        return implode('', $writer->writtenLog());
    }

    private function config(array $overrides = []): Config
    {
        return new Config(array_merge(['host' => '10.0.0.1', 'user' => 'admin', 'pass' => 'secret'], $overrides));
    }

    public function testModernLoginSendsNameAndPasswordAndSucceedsOnDone(): void
    {
        $transport  = new FakeTransport();
        $transport->pushRead($this->sentenceBytes('!done', ['.tag=1']));
        $connection = new Connection($transport);

        Authenticator::login($connection, $this->config());

        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('/login', $sent);
        $this->assertStringContainsString('=name=admin', $sent);
        $this->assertStringContainsString('=password=secret', $sent);
    }

    public function testModernLoginThrowsBadCredentialsOnTrap(): void
    {
        $transport  = new FakeTransport();
        // RouterOS follows a !trap with a !done that actually closes the
        // command's response — Future only settles on the terminal sentence.
        $transport->pushRead(
            $this->sentenceBytes('!trap', ['=message=invalid user name or password', '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );
        $connection = new Connection($transport);

        $this->expectException(BadCredentialsException::class);
        Authenticator::login($connection, $this->config());
    }

    public function testLegacyLoginComputesMd5ChallengeResponse(): void
    {
        $transport = new FakeTransport();

        $challenge = bin2hex(random_bytes(16));
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=ret=' . $challenge, '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $connection = new Connection($transport);

        // Login is two sequential commands (challenge, then response). The
        // second command's reply can't exist on the wire until the second
        // command is actually sent — a real RouterOS never replies early —
        // so drive login() in a Fiber and only push that reply once it's
        // genuinely blocked waiting for it.
        $fiber = new Fiber(function () use ($connection) {
            Authenticator::login($connection, $this->config(['legacy' => true]));
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended(), 'expected login() to be blocked waiting on the response reply');

        $transport->pushRead($this->sentenceBytes('!done', ['.tag=2']));
        $this->assertTrue($fiber->isTerminated());

        $expectedResponse = '00' . md5(chr(0) . 'secret' . pack('H*', $challenge));
        $sent = implode('', $transport->writtenLog());
        $this->assertStringContainsString('=name=admin', $sent);
        $this->assertStringContainsString('=response=' . $expectedResponse, $sent);
    }

    public function testLegacyLoginThrowsBadCredentialsOnTrap(): void
    {
        $transport = new FakeTransport();
        $challenge = bin2hex(random_bytes(16));
        $transport->pushRead(
            $this->sentenceBytes('!re', ['=ret=' . $challenge, '.tag=1'])
            . $this->sentenceBytes('!done', ['.tag=1'])
        );

        $connection = new Connection($transport);

        $caught = null;
        $fiber  = new Fiber(function () use ($connection, &$caught) {
            try {
                Authenticator::login($connection, $this->config(['legacy' => true]));
            } catch (BadCredentialsException $e) {
                $caught = $e;
            }
        });
        $fiber->start();
        $this->assertTrue($fiber->isSuspended());

        $transport->pushRead(
            $this->sentenceBytes('!trap', ['=message=invalid user name or password', '.tag=2'])
            . $this->sentenceBytes('!done', ['.tag=2'])
        );

        $this->assertInstanceOf(BadCredentialsException::class, $caught);
    }
}
