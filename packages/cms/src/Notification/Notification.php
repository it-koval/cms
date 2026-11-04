<?php

declare(strict_types=1);

namespace Itk\Cms\Notification;

use Itk\Cms\I18n\I18n;

/**
 * One message for the people who run the site: «новая заявка», «заявка не дошла в Telegram». The core and the site's code hand it to
 * NotifierInterface::notify(); the channels (itk.notification_channel) decide where it goes: the bell of the admin, Telegram, e-mail….
 *
 *   $notifier->notify(new Notification(
 *       type: 'order.paid', title: 'Оплачен заказ №15', body: '3 200 ₽', link: '/collections/orders', level: Notification::INFO,
 *       audience: 'col:orders', payload: ['orderId' => 15],
 *   ));
 *
 * type      what happened, a short code ('request.new', 'telegram.failed', 'ai.suggestion' is kept for the suggestions of the assistant)
 * link      where a click leads: a route of the admin ('/requests/12') or a full address (http/https)
 * audience  who is told: null = every signed-in person; a permission area or 'area:action' ('requests', 'col:tours:edit', 'set:users'); 'role:<key>' = one role
 * payload   data for the channels, plain JSON (ids, numbers; no secrets: the bell shows it to the staff)
 * key       a name of the matter: the bell keeps one notification per key (a repeated failure of the same request does not pile up)
 *
 * Notifications of the core are made with translated(): the bell keeps the keys of their texts and draws them in the language of the reader, `title` and `body` are the same text in the language
 * of the moment, for the channels and as a fallback. The plain constructor is for the text of a site, kept and shown as it is.
 */
final class Notification
{
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const LEVELS = [self::INFO, self::WARNING, self::ERROR];

    public const REQUEST_NEW = 'request.new';
    public const TELEGRAM_FAILED = 'telegram.failed';
    public const MAIL_FAILED = 'mail.failed';
    /** Reserved for the suggestions of the assistant. */
    public const AI_SUGGESTION = 'ai.suggestion';

    /**
     * @param array<string, mixed> $payload
     * @param array{title?: array{key: string, params: array<string, mixed>}, body?: array{key: string, params: array<string, mixed>}}|null $i18n the keys of the texts, see translated()
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $body = '',
        public readonly ?string $link = null,
        public readonly string $level = self::INFO,
        public readonly ?string $audience = null,
        public readonly array $payload = [],
        public readonly ?string $key = null,
        public readonly ?array $i18n = null,
    ) {
        if ('' === \trim($type) || \strlen($type) > 64) {
            throw new \InvalidArgumentException('Notification type must be 1–64 characters.');
        }
        if ('' === \trim($title)) {
            throw new \InvalidArgumentException('Notification needs a title.');
        }
        if (!\in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(\sprintf('Notification level must be one of: %s.', \implode(', ', self::LEVELS)));
        }
        if (null !== $link && !\preg_match('~^(/(?![/\\\\])|https?://)~i', $link)) {
            throw new \InvalidArgumentException('Notification link must be a route of the admin ("/requests/12") or an http(s) address.');
        }
        if (null !== $key && (\strlen($key) > 120 || '' === $key)) {
            throw new \InvalidArgumentException('Notification key must be 1–120 characters.');
        }
    }

    /**
     * A notification whose title (and body, with $bodyKey) are keys of the catalogues. A body without a key is a text that has no translation: the error of a server, the data of a request.
     *
     * @param array<string, mixed> $titleParams
     * @param array<string, mixed> $bodyParams
     * @param array<string, mixed> $payload
     */
    public static function translated(
        string $type,
        string $titleKey,
        array $titleParams = [],
        string $body = '',
        ?string $bodyKey = null,
        array $bodyParams = [],
        ?string $link = null,
        string $level = self::INFO,
        ?string $audience = null,
        array $payload = [],
        ?string $key = null,
    ): self {
        $i18n = ['title' => ['key' => $titleKey, 'params' => $titleParams]];
        if (null !== $bodyKey) {
            $i18n['body'] = ['key' => $bodyKey, 'params' => $bodyParams];
        }

        return new self($type, I18n::t($titleKey, $titleParams), null === $bodyKey ? $body : I18n::t($bodyKey, $bodyParams), $link, $level, $audience, $payload, $key, $i18n);
    }
}
