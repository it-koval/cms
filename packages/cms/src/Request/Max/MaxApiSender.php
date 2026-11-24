<?php

declare(strict_types=1);

namespace Itk\Cms\Request\Max;

use Itk\Cms\Bots\BotSettings;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\DeliveryUncertainException;
use MaxMessenger\Bot\Exception\Validation\ValidationException;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\MaxApiConfig;
use MaxMessenger\Bot\Model\Enum\TextFormat;
use MaxMessenger\Bot\Model\Request\NewMessageBody;
use Mj4444\SimpleHttpClient\Exceptions\HttpResponse\Http\HttpException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The admin token wins, then the site's bundle client is the fallback. Secrets are read again before each delivery.
 * The MAX client package is optional; an absent package or token leaves requests pending.
 *
 * @internal
 */
final class MaxApiSender implements MaxSender, ResetInterface
{
    /** The longest text MAX takes in one message, in characters. */
    public const TEXT_LIMIT = 4000;

    private bool $resolved = false;
    private ?MaxApiClient $api = null;
    private bool $hasToken = false;
    private string $token = '';
    private ?string $route = null;
    private ?string $resolutionKey = null;
    private bool $broken = false;

    /**
     * @param \Closure(): ?object|null $client builds the client on first use (null when the site has no MAX bundle): a closure, so that a token nobody set does not break every page that needs this class
     * @param \Closure(): ?object|null $config the configuration of that client, to see whether it has a token
     * @param (\Closure(string): ?object)|null $adminClient optional client factory for an admin token
     */
    public function __construct(
        private readonly ?\Closure $client,
        private readonly ?\Closure $config,
        private readonly LoggerInterface $logger,
        private readonly ?BotSettings $settings = null,
        private readonly ?\Closure $adminClient = null,
        private readonly ?MaxClientFactory $adminFactory = null,
    ) {
    }

    public function isConnected(): bool
    {
        $this->resolve();

        return null !== $this->api && $this->hasToken;
    }

    public function isConfigured(): bool
    {
        return $this->isConnected();
    }

    public function source(): ?string
    {
        $this->resolve();

        return $this->route;
    }

    public function problem(bool $includeUnconfigured = true): ?string
    {
        if ($this->settings?->unreadable('max')) {
            return I18n::t('http.bot_token_unreadable', ['channel' => 'MAX']);
        }
        $this->resolve();
        if ('admin' === $this->route && (!\class_exists(MaxApiClient::class) || $this->broken)) {
            return I18n::t('http.max_problem_client');
        }
        if (!$includeUnconfigured || $this->isConnected()) {
            return null;
        }

        return I18n::t(!\class_exists(MaxApiClient::class) || $this->broken ? 'http.max_problem_client' : 'http.max_problem_token');
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->api = null;
        $this->hasToken = false;
        $this->token = '';
        $this->route = null;
        $this->resolutionKey = null;
        $this->broken = false;
    }

    /**
     * @throws DeliveryUncertainException when the provider may already have accepted the message
     * @throws MaxDeliveryException when a send is refused or no connection is available
     */
    public function send(int|string $chatId, string $html): void
    {
        $this->resolve();
        if (null === $this->api || !$this->hasToken) {
            throw new MaxDeliveryException('MAX is not connected');
        }
        $id = \filter_var((string) $chatId, \FILTER_VALIDATE_INT);
        if (false === $id) {
            throw new MaxDeliveryException('MAX chat id must be a whole number');
        }
        try {
            $this->api->sendMessageToChat($id, new NewMessageBody(self::cut($html), format: TextFormat::Html), true);
        } catch (\Throwable $e) {
            $status = $e instanceof HttpException ? $e->getResponse()->getHttpCode() : 0;
            if (!$e instanceof ValidationException && ($status < 400 || $status >= 500 || 408 === $status)) {
                throw new DeliveryUncertainException(I18n::t('http.request_delivery_uncertain'));
            }
            $reason = '' !== \trim($e->getMessage()) ? $e->getMessage() : $e::class;

            throw new MaxDeliveryException($this->scrub($reason));
        }
    }

    /** Cut at the limit, never in the middle of a tag (<a href=...) or an entity (&amp;): MAX would refuse the text. */
    public static function cut(string $html): string
    {
        if (\mb_strlen($html) <= self::TEXT_LIMIT) {
            return $html;
        }

        $cut = (string) \preg_replace('/<[^<>]*$/', '', \mb_substr($html, 0, self::TEXT_LIMIT));

        return (string) \preg_replace('/&[#\w]*$/', '', $cut);
    }

    private function resolve(): void
    {
        $adminToken = $this->settings?->token('max');
        $key = null === $adminToken ? 'server' : 'admin:' . \hash('sha256', $adminToken);
        if ($this->resolved && $key === $this->resolutionKey) {
            return;
        }
        $this->reset();
        $this->resolved = true;
        $this->resolutionKey = $key;
        $this->route = null !== $adminToken ? 'admin' : null;
        if (!\class_exists(MaxApiClient::class)) {
            return;
        }
        try {
            if (null !== $adminToken) {
                $this->token = $adminToken;
                $server = null;
                try {
                    $server = null === $this->config ? null : ($this->config)();
                } catch (\Throwable) {
                    // A missing server token must not stop an admin token from working.
                }
                $api = null !== $this->adminClient ? ($this->adminClient)($adminToken)
                    : ($this->adminFactory ?? new MaxClientFactory())->create($adminToken, $server instanceof MaxApiConfig ? $server : null);
                $hasToken = true;
                $source = 'admin';
            } else {
                if (null === $this->client) {
                    return;
                }
                $server = null === $this->config ? null : ($this->config)();
                $token = self::readToken($server);
                $this->token = $token ?? '';
                $hasToken = null === $token || '' !== \trim($token);
                if (!$hasToken) {
                    return;
                }
                $this->route = 'server';
                // The bundle's default HTTP client can repeat POST on a timeout or 5xx.
                $api = $server instanceof MaxApiConfig
                    ? new MaxApiClient((clone $server)->setRetryAttempts([]))
                    : ($this->client)();
                $source = 'server';
            }
            if (!$api instanceof MaxApiClient) {
                $this->broken = true;

                return;
            }
            $this->api = $api;
            $this->hasToken = $hasToken;
            $this->route = $source;
        } catch (\Throwable $e) {
            $this->api = null;
            $this->broken = true;
            // A configuration failure can happen before its token is known, so even a cleaned exception message is unsafe.
            $this->logger->warning('MAX is not used, its client cannot be built: {error}', ['error' => $e::class]);
        }
    }

    /** The client does not tell its token; the configuration does. Null when there is no configuration service: the client is then taken as configured. */
    private static function readToken(?object $config): ?string
    {
        if (null === $config || !\method_exists($config, 'getAccessToken')) {
            return null;
        }
        $token = $config->getAccessToken();
        $value = $token instanceof \SensitiveParameterValue ? $token->getValue() : $token;

        return \is_string($value) ? $value : '';
    }

    /** The token goes in a header, but an error text may quote more than it should: never show or log it. */
    private function scrub(string $message): string
    {
        return \Itk\Cms\Logging\SecretScrubber::scrub($message, [$this->token]);
    }
}
