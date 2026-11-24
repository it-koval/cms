<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Itk\Cms\I18n\I18n;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Stops Symfony's compound transports from retrying an unconfirmed delivery. One guard per send. @internal.
 *
 * @internal
 */
final class RequestMailTransportGuard implements EventDispatcherInterface
{
    private bool $accepted = false;

    public function __construct(private readonly EventDispatcherInterface $dispatcher)
    {
    }

    public function transport(#[\SensitiveParameter] string $dsn): TransportInterface
    {
        $factories = [];
        foreach (Transport::getDefaultFactories($this) as $factory) {
            $factories[] = new class($factory, $this) implements TransportFactoryInterface {
                public function __construct(private readonly TransportFactoryInterface $factory, private readonly RequestMailTransportGuard $guard)
                {
                }

                public function supports(Dsn $dsn): bool
                {
                    return $this->factory->supports($dsn);
                }

                public function create(Dsn $dsn): TransportInterface
                {
                    return $this->guard->protect($this->factory->create($dsn));
                }
            };
        }

        return (new Transport($factories))->fromString($dsn);
    }

    public function protect(TransportInterface $transport): TransportInterface
    {
        return new class($transport, $this) implements TransportInterface {
            public function __construct(private readonly TransportInterface $transport, private readonly RequestMailTransportGuard $guard)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                return $this->guard->send($this->transport, $message, $envelope);
            }

            public function __toString(): string
            {
                return (string) $this->transport;
            }
        };
    }

    public function send(TransportInterface $transport, RawMessage $message, ?Envelope $envelope): ?SentMessage
    {
        try {
            return $transport->send($message, $envelope);
        } catch (TransportExceptionInterface $error) {
            // A 4xx/5xx after acceptance can come from a listener or an SMTP reconnect.
            if (!$this->accepted && $transport instanceof SmtpTransport && $error instanceof UnexpectedResponseException && $error->getCode() >= 400 && $error->getCode() <= 599) {
                throw $error;
            }

            // Symfony's failover and round-robin only catch Mailer's transport exceptions.
            throw new MailDeliveryUnknownException(I18n::t('http.mail_delivery_unknown'));
        } finally {
            $this->accepted = false;
        }
    }

    public function dispatch(object $event): object
    {
        if ($event instanceof SentMessageEvent) {
            $this->accepted = true;
        }
        try {
            return $this->dispatcher->dispatch($event);
        } catch (TransportExceptionInterface $error) {
            if ($event instanceof FailedMessageEvent && $error !== $event->getError()) {
                // A listener's own SMTP error cannot establish what happened to this message.
                throw new MailDeliveryUnknownException(I18n::t('http.mail_delivery_unknown'));
            }

            throw $error;
        }
    }
}
