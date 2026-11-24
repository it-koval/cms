<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Mail\MailSettings;
use Itk\Cms\Mail\SendMail;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Для тестов почты: Mailpit стенда и очередь async в памяти.
 * Адрес Mailpit (веб и API) — переменная окружения ITK_TEST_MAILPIT_URL, например http://127.0.0.1:33952; SMTP — тот же хост
 * и порт ITK_TEST_MAILPIT_SMTP_PORT (по умолчанию 1025). Письма уходят только в Mailpit и только на адреса @example.com.
 * Без ITK_TEST_MAILPIT_URL тесты, которым нужен Mailpit, пропускаются; если адрес задан, а Mailpit не отвечает, они падают.
 */
trait MailSupport
{
    private function mailpitUrl(): ?string
    {
        $url = \getenv('ITK_TEST_MAILPIT_URL');

        return \is_string($url) && '' !== \trim($url) ? \rtrim(\trim($url), '/') : null;
    }

    private function mailpitApi(): string
    {
        return ($this->mailpitUrl() ?? '') . '/api/v1';
    }

    private function mailpitSmtpHost(): string
    {
        return (string) (\parse_url($this->mailpitUrl() ?? '', \PHP_URL_HOST) ?: 'localhost');
    }

    private function mailpitSmtpPort(): int
    {
        return (int) (\getenv('ITK_TEST_MAILPIT_SMTP_PORT') ?: 1025);
    }

    /** MAILER_DSN этого теста: без него в окружении теста письма «не настроены» (null://null). */
    private function mailerEnv(string $dsn = 'null://null'): void
    {
        $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = $dsn;
        \putenv('MAILER_DSN=' . $dsn);
    }

    private function restoreMailerEnv(): void
    {
        unset($_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']);
        \putenv('MAILER_DSN');
    }

    /** Включает «Настройки → Почта» с Mailpit. @param array<string, mixed> $override */
    private function useMailpit(array $override = []): void
    {
        $this->requireMailpit();
        self::getContainer()->get(MailSettings::class)->save($override + [
            'enabled' => true, 'host' => $this->mailpitSmtpHost(), 'port' => $this->mailpitSmtpPort(), 'encryption' => 'none',
            'username' => '', 'clearPassword' => true, 'fromAddress' => 'test-site@example.com', 'fromName' => 'Тестовый сайт',
        ]);
    }

    private function requireMailpit(): void
    {
        if (null === $this->mailpitUrl()) {
            self::markTestSkipped('Mailpit не задан (переменная ITK_TEST_MAILPIT_URL): тест отправки пропущен.');
        }
        try {
            HttpClient::create(['timeout' => 2])->request('GET', $this->mailpitApi() . '/messages?limit=1')->getStatusCode();
        } catch (\Throwable $e) {
            self::fail(\sprintf('ITK_TEST_MAILPIT_URL задан (%s), но Mailpit не отвечает: %s', $this->mailpitUrl(), $e->getMessage()));
        }
    }

    private function wipeMailSettings(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $em->createQuery("DELETE FROM Itk\\Cms\\Entity\\Setting s WHERE s.key IN ('itk:mail', 'itk:mailStatus', 'mailRoutes')")->execute();
    }

    /** Письма, которые страница поставила в очередь. @return list<SendMail> */
    private function queuedMail(): array
    {
        $out = [];
        foreach (self::getContainer()->get('messenger.transport.async')->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof SendMail) {
                $out[] = $envelope->getMessage();
            }
        }

        return $out;
    }

    private function clearQueue(): void
    {
        $t = self::getContainer()->get('messenger.transport.async');
        \assert($t instanceof InMemoryTransport);
        $t->reset();
    }

    /** @return list<array<string, mixed>> письма в Mailpit для адреса */
    private function mailpitFor(string $to): array
    {
        $r = HttpClient::create(['timeout' => 5])->request('GET', $this->mailpitApi() . '/search', ['query' => ['query' => 'to:' . $to]])->toArray();

        return $r['messages'] ?? [];
    }

    /** @return array<string, mixed> */
    private function mailpitMessage(string $id): array
    {
        return HttpClient::create(['timeout' => 5])->request('GET', $this->mailpitApi() . '/message/' . $id)->toArray();
    }

    private function mailpitWipe(string $to): void
    {
        try {
            HttpClient::create(['timeout' => 5])->request('DELETE', $this->mailpitApi() . '/search', ['query' => ['query' => 'to:' . $to]])->getStatusCode();
        } catch (\Throwable) {
        }
    }

    /** Ссылка для пароля из текста письма. */
    private function linkIn(SendMail $mail): string
    {
        self::assertSame(1, \preg_match('~https?://[^\s"<]+/admin/reset-password/[A-Za-z0-9_-]+~', $mail->text, $m), 'в письме есть ссылка');

        return $m[0];
    }
}
