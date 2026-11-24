<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Mail\MailDeliveryUnknownException;
use Itk\Cms\Mail\RequestMailSmtpTransport;
use Itk\Cms\Mail\RequestMailTransportGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

final class RequestMailTransportGuardTest extends TestCase
{
    public function testSmtpDsnOptionsMatchTheSymfonyFactory(): void
    {
        foreach (['smtp', 'smtps'] as $scheme) {
            $dsn = Dsn::fromString($scheme . '://fixture:fixture@smtp.example.test:2525?auto_tls=false&require_tls=true&source_ip=127.0.0.1&verify_peer=false&peer_fingerprint=fixture&local_domain=example.test&max_per_second=5&restart_threshold=3&restart_threshold_sleep=1&ping_threshold=7');
            $dispatcher = new EventDispatcher();
            $expected = (new EsmtpTransportFactory($dispatcher))->create($dsn);
            self::assertInstanceOf(EsmtpTransport::class, $expected);
            $actual = RequestMailSmtpTransport::fromDsn($dsn, $dispatcher);
            self::assertSame($expected->isAutoTls(), $actual->isAutoTls());
            self::assertSame($expected->isTlsRequired(), $actual->isTlsRequired());
            self::assertSame($expected->getUsername(), $actual->getUsername());
            self::assertSame($expected->getPassword(), $actual->getPassword());
            self::assertSame($expected->getLocalDomain(), $actual->getLocalDomain());
            $expectedStream = $expected->getStream();
            $actualStream = $actual->getStream();
            self::assertInstanceOf(SocketStream::class, $expectedStream);
            self::assertInstanceOf(SocketStream::class, $actualStream);
            self::assertSame($expectedStream->getHost(), $actualStream->getHost());
            self::assertSame($expectedStream->getPort(), $actualStream->getPort());
            self::assertSame($expectedStream->isTLS(), $actualStream->isTLS());
            self::assertSame($expectedStream->getSourceIp(), $actualStream->getSourceIp());
            self::assertSame($expectedStream->getStreamOptions(), $actualStream->getStreamOptions());
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function smtpFailures(): iterable
    {
        yield 'lost greeting' => ['greeting', 'auto_tls=false', true];
        yield 'TLS required but unavailable' => ['tls-required', 'auto_tls=false&require_tls=true', true];
        yield 'STARTTLS handshake closed' => ['starttls', 'auto_tls=true', true];
        yield 'AUTH failure is a plain transport exception' => ['auth', 'auto_tls=false', true];
        yield 'connection lost before MAIL FROM reply' => ['mail', 'auto_tls=false', true];
        yield 'DATA explicitly refused' => ['data-refused', 'auto_tls=false', true];
        yield 'connection lost before DATA readiness reply' => ['data', 'auto_tls=false', true];
        yield 'connection lost after DATA readiness reply' => ['ready', 'auto_tls=false', false];
        yield 'connection lost after message body' => ['body', 'auto_tls=false', false];
    }

    #[DataProvider('smtpFailures')]
    #[RequiresPhpExtension('pcntl')]
    public function testOnlyFailuresBeforeDataOrExplicitRefusalsPermitFailover(string $failure, string $query, bool $safe): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server, (string) $error);
        $address = \stream_socket_get_name($server, false);
        self::assertIsString($address);
        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid);
        if (0 === $pid) {
            $client = \stream_socket_accept($server, 3);
            if (false === $client) {
                exit(1);
            }
            \stream_set_timeout($client, 3);
            if ('greeting' !== $failure) {
                \fwrite($client, "220 fixture ready\r\n");
                $body = false;
                while (false !== ($line = \fgets($client))) {
                    if ($body) {
                        if (".\r\n" === $line) {
                            break; // The server may have accepted the body, but its final reply was lost.
                        }
                        continue;
                    }
                    if (\str_starts_with($line, 'EHLO ')) {
                        \fwrite($client, match ($failure) {
                            'auth' => "250-fixture\r\n250 AUTH PLAIN\r\n",
                            'starttls' => "250-fixture\r\n250 STARTTLS\r\n",
                            default => "250 fixture\r\n",
                        });
                    } elseif (\str_starts_with($line, 'STARTTLS')) {
                        \fwrite($client, "220 start TLS\r\n");
                        break;
                    } elseif (\str_starts_with($line, 'AUTH ')) {
                        \fwrite($client, "535 fixture refused credentials\r\n");
                    } elseif ("RSET\r\n" === $line && \in_array($failure, ['auth', 'data-refused'], true)) {
                        \fwrite($client, "250 reset\r\n");
                        break;
                    } elseif (\str_starts_with($line, 'MAIL FROM:') && 'mail' === $failure) {
                        break;
                    } elseif ("DATA\r\n" === $line) {
                        if ('data' === $failure) {
                            break;
                        }
                        $body = 'data-refused' !== $failure;
                        \fwrite($client, $body ? "354 send body\r\n" : "550 fixture refused DATA\r\n");
                        if ('ready' === $failure) {
                            break;
                        }
                    } elseif ("QUIT\r\n" === $line) {
                        \fwrite($client, "221 closing\r\n");
                        break;
                    } else {
                        \fwrite($client, "250 OK\r\n");
                    }
                }
            }
            \fclose($client);
            \fclose($server);
            exit(0);
        }
        \fclose($server);
        $fallbacks = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event) use (&$fallbacks): void {
            if ('null://' === $event->getTransport()) {
                ++$fallbacks;
            }
        });
        $guard = new RequestMailTransportGuard($dispatcher);
        $transport = $guard->transport('failover(smtp://fixture:fixture@' . $address . '?' . $query . ' null://null)');
        $message = (new Email())->from('site@example.com')->to('recipient@example.com')->subject('Fixture')->text('Fixture');
        try {
            if (!$safe) {
                $this->expectException(MailDeliveryUnknownException::class);
            }
            $transport->send($message);
        } finally {
            \pcntl_waitpid($pid, $status);
            self::assertTrue(\pcntl_wifexited($status));
            self::assertSame(0, \pcntl_wexitstatus($status));
            self::assertSame($safe ? 1 : 0, $fallbacks, 'fallback is allowed only when this SMTP attempt could not have accepted the message');
        }
    }

    public function testConnectionRefusedPermitsFailoverBeforeData(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server, (string) $error);
        $address = \stream_socket_get_name($server, false);
        self::assertIsString($address);
        \fclose($server);
        $guard = new RequestMailTransportGuard(new EventDispatcher());
        self::assertNotNull($guard->transport('failover(smtp://' . $address . '?auto_tls=false null://null)')->send(
            (new Email())->from('site@example.com')->to('recipient@example.com')->text('Fixture'),
        ));
    }
}
