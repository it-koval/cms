<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\I18n;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends letters of the site: to the queue (a page does not wait for the mail server) or at once (the «Отправить тестовое письмо» button shows the real error).
 * The connection is built from «Настройки → Почта» at the moment of sending (MailerFactory). Errors are cleaned of passwords before they are kept, logged or shown.
 */
final class SiteMailer
{
    public function __construct(
        private readonly MailerFactory $factory,
        private readonly MailStatus $status,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->factory->isConfigured();
    }

    /** Why mail is not configured, in plain words; null when it is. */
    public function problem(): ?string
    {
        return $this->factory->problem();
    }

    /**
     * Puts the letter into the queue. Returns only after the queue took it, so the caller may say «письмо отправлено» (it will go within a minute).
     *
     * @throws ContentException 503 when mail is not configured or the queue is unavailable
     */
    public function queue(SendMail $mail): void
    {
        if (!$this->isConfigured()) {
            throw new ContentException($this->problem() ?? I18n::t('http.mail_not_configured'), 503, ['code' => 'mail_not_configured']);
        }
        try {
            $this->bus->dispatch($mail);
        } catch (\Throwable $e) {
            $this->logger->error('Could not queue a {kind} letter: {error}', ['kind' => $mail->kind, 'error' => $this->clean($e->getMessage())]);

            throw new ContentException(I18n::t('http.mail_queue_failed_reason', ['reason' => $this->clean($e->getMessage())]), 503, ['code' => 'mail_queue']);
        }
    }

    /**
     * Sends the letter now (the worker, the test button).
     *
     * @throws MailDeliveryException with a cleaned text for the screen
     */
    public function deliver(SendMail $mail): void
    {
        $route = $this->factory->route();
        if (null === $route) {
            $this->failed($mail, $this->factory->problem() ?? I18n::t('http.mail_not_configured'));
        }
        try {
            $email = (new Email())
                ->from($route->from)
                ->to(new Address($mail->to))
                ->subject($mail->subject)
                ->text($mail->text)
                ->html($mail->html);
            $this->factory->transport($route)->send($email);
        } catch (\Throwable $e) {
            $this->failed($mail, $this->reason($e, $route->secrets, $route->usernames), $route->secrets, $route->usernames);
        }
        $this->status->sent();
    }

    /** @param list<string> $secrets */
    private function failed(SendMail $mail, string $reason, #[\SensitiveParameter] array $secrets = [], #[\SensitiveParameter] array $usernames = []): never
    {
        $reason = DsnBuilder::clean($reason, $secrets, $usernames);
        $this->logger->error('Could not send a {kind} letter: {error}', ['kind' => $mail->kind, 'error' => $reason]);
        $this->status->failed($mail->kind, $reason);

        throw new MailDeliveryException($reason);
    }

    /** @param list<string> $secrets */
    private function reason(\Throwable $e, #[\SensitiveParameter] array $secrets, #[\SensitiveParameter] array $usernames = []): string
    {
        $text = DsnBuilder::clean(\trim(\preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage()), $secrets, $usernames);

        return '' === $text ? I18n::t('http.mail_rejected') : \mb_substr($text, 0, 300);
    }

    private function clean(string $text): string
    {
        return \mb_substr(\trim(\preg_replace('/\s+/', ' ', $text) ?? $text), 0, 300);
    }
}
