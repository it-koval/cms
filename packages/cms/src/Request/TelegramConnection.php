<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\Bots\BotSettings;
use Itk\Cms\I18n\I18n;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/** The site's optional Telegram transport, shared by request delivery and validation of the routing settings. @internal */
final class TelegramConnection implements ResetInterface
{
    private bool $resolved = false;
    private ?TransportInterface $transport = null;
    private ?TelegramTransport $adminTransport = null;
    private ?string $adminToken = null;

    /** @param (\Closure(): ?object)|null $chatter builds the chatter on first use, reading the site's optional TELEGRAM_DSN */
    public function __construct(
        private readonly ?\Closure $chatter,
        private readonly LoggerInterface $logger,
        private readonly ?BotSettings $bots = null,
        private readonly ?HttpClientInterface $http = null,
    ) {
    }

    /**
     * Error texts of the HTTP client carry the request address, and the address carries the bot token: never show or log it. The same goes for a DSN quoted by a configuration error.
     *
     * @param list<string> $tokens
     */
    public static function scrub(string $message, #[\SensitiveParameter] array $tokens = []): string
    {
        return \Itk\Cms\Logging\SecretScrubber::scrub($message, $tokens);
    }

    public function scrubError(string $message): string
    {
        return self::scrub($message, [$this->adminToken ?? '', $this->bots?->token('telegram') ?? '']);
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->transport = null;
        $this->adminTransport = null;
        $this->adminToken = null;
    }

    public function isConnected(): bool
    {
        return null !== $this->transport();
    }

    /** Which token is selected; the admin token wins even when an optional sending package is missing. */
    public function source(): ?string
    {
        return null !== $this->bots?->token('telegram') ? 'admin' : (null !== $this->serverTransport() ? 'server' : null);
    }

    public function problem(bool $includeUnconfigured = true): ?string
    {
        if ($this->bots?->unreadable('telegram')) {
            return I18n::t('http.bot_token_unreadable', ['channel' => 'Telegram']);
        }
        if (null !== $this->bots?->token('telegram') && !$this->adminPackagesAvailable()) {
            return I18n::t('http.telegram_packages_missing');
        }
        if ($includeUnconfigured && !$this->isConnected()) {
            return I18n::t('http.telegram_problem_token');
        }

        return null;
    }

    public function transport(): ?TransportInterface
    {
        $token = $this->bots?->token('telegram');
        if (null === $token) {
            $this->adminToken = null;
            $this->adminTransport = null;

            return $this->serverTransport();
        }
        if (!$this->adminPackagesAvailable()) {
            return null;
        }
        if ($token !== $this->adminToken || null === $this->adminTransport) {
            $this->adminToken = $token;
            $client = $this->http instanceof RetryableHttpClient ? $this->http->withOptions(['max_retries' => 0]) : $this->http;
            $this->adminTransport = new TelegramTransport($token, client: $client);
        }

        return $this->adminTransport;
    }

    private function adminPackagesAvailable(): bool
    {
        return \class_exists(ChatMessage::class) && \class_exists(TelegramOptions::class) && \class_exists(TelegramTransport::class)
            && (null !== $this->http || \class_exists(HttpClient::class));
    }

    /**
     * The chatter of the site, built once; null when Telegram cannot be used: no Notifier or Telegram bridge installed, no transport configured, the DSN is null://null,
     * the variable of the DSN (TELEGRAM_DSN) not set, or a DSN that cannot be built. The reason is logged when it is a mistake of the configuration (a missing variable is not one: Telegram is optional).
     */
    private function serverTransport(): ?TransportInterface
    {
        if ($this->resolved) {
            return $this->transport;
        }
        $this->resolved = true;
        if (null === $this->chatter || !\class_exists(ChatMessage::class) || !\class_exists(TelegramOptions::class)) {
            return null;
        }
        try {
            $chatter = ($this->chatter)();
            $this->transport = $chatter instanceof TransportInterface && self::canDeliver($chatter) ? $chatter : null;
        } catch (EnvNotFoundException) {
            // TELEGRAM_DSN is not set: Telegram is not used on this site
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram is not used, its transport cannot be built: {error}', ['error' => self::scrub($e::class . ': ' . $e->getMessage())]);
        }

        return $this->transport;
    }

    /**
     * Does the transport really send? framework.notifier without chatter_transports still has a (empty) transport collection: it prints as «[]».
     * null://null means «ничего не отправлять» (TELEGRAM_DSN in the .env of a new site), the same as for mail. The collection does not show its transports, but a null transport takes any message and a chatter only chat messages.
     */
    private static function canDeliver(TransportInterface $chatter): bool
    {
        return '[]' !== (string) $chatter && !$chatter->supports(new SmsMessage('+10000000000', 'probe'));
    }
}
