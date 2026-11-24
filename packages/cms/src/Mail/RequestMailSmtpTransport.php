<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\RawMessage;

/**
 * Tracks the SMTP acceptance boundary without inspecting or retaining SMTP debug output.
 *
 * @internal
 */
#[Exclude]
final class RequestMailSmtpTransport extends EsmtpTransport
{
    private bool $dataStarted = false;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->dataStarted = false;

        return parent::send($message, $envelope);
    }

    public function executeCommand(string $command, array $codes): string
    {
        $response = parent::executeCommand($command, $codes);
        if ("DATA\r\n" === $command) {
            // Symfony writes the message only after DATA receives 354. Until then no body could be sent.
            $this->dataStarted = true;
        }

        return $response;
    }

    public function dataStarted(): bool
    {
        return $this->dataStarted;
    }

    /** Keep the SMTP DSN options of Symfony's EsmtpTransportFactory; it has no transport creation hook. */
    public static function fromDsn(Dsn $dsn, EventDispatcherInterface $dispatcher): self
    {
        $autoTls = '' === $dsn->getOption('auto_tls') || \filter_var($dsn->getOption('auto_tls', true), \FILTER_VALIDATE_BOOL);
        $tls = 'smtps' === $dsn->getScheme() ? true : ($autoTls ? null : false);
        $transport = new self($dsn->getHost(), $dsn->getPort(0), $tls, $dispatcher);
        $transport->setAutoTls($autoTls);
        $transport->setRequireTls($dsn->getBooleanOption('require_tls'));
        $stream = $transport->getStream();
        \assert($stream instanceof SocketStream);
        if ('' !== $sourceIp = $dsn->getOption('source_ip', '')) {
            $stream->setSourceIp($sourceIp);
        }
        $streamOptions = $stream->getStreamOptions();
        if ('' !== $dsn->getOption('verify_peer') && !\filter_var($dsn->getOption('verify_peer', true), \FILTER_VALIDATE_BOOL)) {
            $streamOptions['ssl']['verify_peer'] = false;
            $streamOptions['ssl']['verify_peer_name'] = false;
        }
        if (null !== $peerFingerprint = $dsn->getOption('peer_fingerprint')) {
            $streamOptions['ssl']['peer_fingerprint'] = $peerFingerprint;
        }
        $stream->setStreamOptions($streamOptions);
        if ($user = $dsn->getUser()) {
            $transport->setUsername($user);
        }
        if ($password = $dsn->getPassword()) {
            $transport->setPassword($password);
        }
        if (null !== ($localDomain = $dsn->getOption('local_domain'))) {
            $transport->setLocalDomain($localDomain);
        }
        if (null !== ($maxPerSecond = $dsn->getOption('max_per_second'))) {
            $transport->setMaxPerSecond((float) $maxPerSecond);
        }
        if (null !== ($restartThreshold = $dsn->getOption('restart_threshold'))) {
            $transport->setRestartThreshold((int) $restartThreshold, (int) $dsn->getOption('restart_threshold_sleep', 0));
        }
        if (null !== ($pingThreshold = $dsn->getOption('ping_threshold'))) {
            $transport->setPingThreshold((int) $pingThreshold);
        }

        return $transport;
    }
}
