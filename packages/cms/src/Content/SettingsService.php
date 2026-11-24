<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Entity\Setting;
use Itk\Cms\History\HistoryService;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Schema\Schema;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Typed key-value site settings. The keys come from the schema ($s->settings('group')->fields(...)) plus the core keys
 * tgOn (bool), tgRoutes ({ '*': chatId, formKey: chatId }), maxOn (bool) and maxRoutes ({ '*': 'id, id', formKey: 'id' }: the chats of MAX, whole numbers), mailRoutes ({ '*': 'a@x.com, b@x.com', formKey: '…' }: who gets a letter about a new request) and forms (bool: accept requests),
 * and the texts robotsTxt / llmsTxt (string, at most 64 KB; '' = the site's own text is not set and the standard one is served, see CrawlerFiles).
 * A field marked translatable() is stored per language, everything else once for all languages (locale '*').
 */
final class SettingsService implements ResetInterface
{
    private const NOTIFICATION_KEYS = ['tgOn', 'maxOn', 'tgRoutes', 'maxRoutes', 'mailRoutes'];

    /** The longest text of robots.txt / llms.txt, in bytes. */
    public const CRAWLER_TEXT_MAX = 65536;

    /** @var array<string, array<string, mixed>>|null locale|'*' => key => value */
    private ?array $loaded = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingRepository $settings,
        private readonly Schema $schema,
        private readonly FieldValues $values,
        private readonly HistoryService $history,
        private readonly FieldAccess $access,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        #[Autowire(param: 'itk_cms.telegram.chat_ids')]
        private readonly string $fallbackChatIds = '',
        #[Autowire(param: 'itk_cms.max.chat_ids')]
        private readonly string $maxFallbackChatIds = '',
    ) {
    }

    /** Between two messages of a worker (kernel.reset): the settings are read again, or it would send a request to a chat that was removed an hour ago. */
    public function reset(): void
    {
        $this->loaded = null;
    }

    /**
     * Shared settings + the texts of one language, flat.
     *
     * @return array<string, mixed>
     */
    public function all(?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $stored = $this->load();
        $out = [];
        foreach ($this->schema->settingsFields() as $f) {
            if ($f->isNote()) {
                continue;
            }
            $scope = $f->isTranslatable() ? $locale : Setting::ALL;
            $out[$f->key()] = \array_key_exists($f->key(), $stored[$scope] ?? []) ? $stored[$scope][$f->key()] : $this->values->emptyFor($f);
        }
        $shared = $stored[Setting::ALL] ?? [];
        foreach ($this->notificationSettings() as $key => $value) {
            if (!\array_key_exists($key, $out)) {
                $out[$key] = $value;
            }
        }
        if (!isset($out['forms'])) {
            $out['forms'] = $shared['forms'] ?? true;
        }
        foreach (Schema::CRAWLER_SETTING_KEYS as $k) {
            if (!isset($out[$k])) { // a site field of the same key keeps its meaning, as with `forms`
                $out[$k] = (string) ($shared[$k] ?? '');
            }
        }

        return $out;
    }

    public function get(string $key, ?string $locale = null, mixed $default = null): mixed
    {
        return $this->all($locale)[$key] ?? $default;
    }

    /** Was this setting ever saved (in the language it belongs to)? An unsaved one reads as the empty value of its field. */
    public function isSet(string $key, ?string $locale = null): bool
    {
        $f = $this->schema->settingsField($key);
        $scope = null !== $f && $f->isTranslatable() ? ($locale ?? $this->mainLocale) : Setting::ALL;

        return \array_key_exists($this->storageKey($key), $this->load()[$scope] ?? []);
    }

    /**
     * Saved at once (no publish step). Only the keys of the patch are checked and written. A signed-in person cannot write a field he may
     * not see or one marked readonly(); the system (console, import, hooks) can.
     *
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed> the merged settings
     */
    public function update(array $patch, ?string $locale = null, string $action = Revision::EDIT): array
    {
        return $this->write($patch, $locale, $action);
    }

    /**
     * Explicit notification scope: API names stay stable even when a site declares a field with the same key.
     *
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    public function updateNotifications(array $patch, ?string $locale = null, string $action = Revision::EDIT): array
    {
        $this->write(\array_intersect_key($patch, \array_flip(Schema::CORE_SETTING_KEYS)), $locale, $action, true);

        return $this->notificationSettings();
    }

    /** @return array<string, mixed> */
    public function notificationSettings(): array
    {
        $shared = $this->load()[Setting::ALL] ?? [];
        $out = ['forms' => $shared['forms'] ?? true];
        foreach (self::NOTIFICATION_KEYS as $key) {
            $out[$key] = $shared['itk:' . $key] ?? (\str_ends_with($key, 'On') ? true : []);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    private function write(array $patch, ?string $locale, string $action, bool $notifications = false): array
    {
        $locale ??= $this->mainLocale;
        $fields = [];
        foreach ($this->schema->settingsFields() as $f) {
            $fields[$f->key()] = $f;
        }
        $core = Schema::CORE_SETTING_KEYS;
        $open = \array_flip(\array_map(static fn ($f): string => $f->key(), $this->access->writable(\array_values($fields))));
        $errors = [];
        $write = [];
        foreach ($patch as $key => $value) {
            $key = (string) $key;
            if (isset($fields[$key]) && !($notifications && \in_array($key, self::NOTIFICATION_KEYS, true))) {
                $f = $fields[$key];
                if ($f->isNote() || !isset($open[$key])) {
                    continue;
                }
                $norm = $this->values->normalize([$f], [$key => $value])[$key] ?? $this->values->emptyFor($f);
                if ('domain' === $key) {
                    self::checkDomain($norm);
                }
                $e = $this->values->validate([$f], [$key => $norm]);
                foreach ($e as $row) {
                    $errors[] = $row;
                }
                $write[$f->isTranslatable() ? $locale : Setting::ALL][$key] = $norm;
            } elseif (\in_array($key, $core, true)) {
                if ('tgRoutes' === $key) {
                    $value = $this->checkRoutes($value);
                } elseif ('maxRoutes' === $key) {
                    $value = $this->checkMaxRoutes($value);
                } elseif ('mailRoutes' === $key) {
                    $value = $this->checkMailRoutes($value);
                } else {
                    $value = (bool) $value;
                }
                $write[Setting::ALL][$key] = $value;
            } elseif (\in_array($key, Schema::CRAWLER_SETTING_KEYS, true)) {
                $error = self::crawlerTextError($value);
                if ('' !== $error) {
                    throw ContentException::invalid($error, [$key => $error]);
                }
                $write[Setting::ALL][$key] = self::crawlerText($value);
            }
        }
        if ([] !== $errors) {
            throw ContentException::invalid(I18n::t('content.settings_required'), $errors);
        }
        $before = $notifications ? $this->notificationSettings() : $this->all($locale);
        $after = $before;
        $touched = [];
        foreach ($write as $items) {
            $after = $items + $after;
            \array_push($touched, ...\array_keys($items));
        }
        $this->history->settings($locale, $before, $after, $touched, $action, $notifications);
        foreach ($write as $scope => $items) {
            foreach ($items as $key => $value) {
                $key = $this->storageKey($key, $notifications);
                $row = $this->settings->find(['key' => $key, 'locale' => $scope]);
                if (null === $row) {
                    $row = new Setting($key, (string) $scope, $value);
                    $this->em->persist($row);
                } else {
                    $row->value = $value;
                }
            }
        }
        $this->em->flush();
        $this->loaded = null;

        return $this->all($locale);
    }

    /** Sets a shared value from code (install, import). */
    public function set(string $key, mixed $value, string $locale = Setting::ALL): void
    {
        if ('domain' === $key) {
            self::checkDomain($value);
        }
        $key = $this->storageKey($key);
        $row = $this->settings->find(['key' => $key, 'locale' => $locale]);
        if (null === $row) {
            $this->em->persist(new Setting($key, $locale, $value));
        } else {
            $row->value = $value;
        }
        $this->em->flush();
        $this->loaded = null;
    }

    private static function checkDomain(mixed $value): void
    {
        if (null === $value || (\is_string($value) && ('' === \trim($value) || null !== SiteAddress::fromDomain($value)))) {
            return;
        }
        $message = I18n::t('content.settings_domain_invalid');
        throw ContentException::invalid($message, ['domain' => $message]);
    }

    /** A shared value that is not a field of the schema, kept by a module of its own (all() never lists it, nor does the settings API or the history). */
    public function stored(string $key): mixed
    {
        return $this->load()[Setting::ALL][$key] ?? null;
    }

    /** stored(), read from the database now: past the cache of this request and the rows Doctrine already holds, so it sees what another request has just written. */
    public function storedNow(string $key): mixed
    {
        $raw = $this->em->getConnection()->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [$key, Setting::ALL]);

        return \is_string($raw) ? \json_decode($raw, true) : null;
    }

    /** Deletes a shared value (the row), not only its content. */
    public function remove(string $key): void
    {
        $key = $this->storageKey($key);
        $row = $this->settings->find(['key' => $key, 'locale' => Setting::ALL]);
        if (null !== $row) {
            $this->em->remove($row);
            $this->em->flush();
        }
        $this->loaded = null;
    }

    /**
     * Telegram chats for a form: the form's own chat, else the «all other forms» chat.
     *
     * @return list<string>
     */
    public function chatIdsFor(string $form): array
    {
        return $this->routedChats('tgRoutes', $form);
    }

    /**
     * MAX chats for a form: the form's own chat, else the «all other forms» chat.
     *
     * @return list<string>
     */
    public function maxChatIdsFor(string $form): array
    {
        return $this->routedChats('maxRoutes', $form);
    }

    /** @return list<string> */
    private function routedChats(string $key, string $form): array
    {
        $routes = (array) ($this->load()[Setting::ALL]['itk:' . $key] ?? []);

        return self::splitIds((string) ($routes[$form] ?? '')) ?: self::splitIds((string) ($routes['*'] ?? ''));
    }

    /** @return list<string> the ids of a list written with commas */
    public static function splitIds(string $list): array
    {
        return \array_values(\array_filter(\array_map('trim', \explode(',', $list)), static fn (string $v): bool => '' !== $v));
    }

    /**
     * E-mail addresses that get a letter about a new request of a form: the form's own list, else the «all other forms» list.
     *
     * @return list<string>
     */
    public function mailRecipientsFor(string $form): array
    {
        $routes = (array) ($this->load()[Setting::ALL]['itk:mailRoutes'] ?? []);

        return self::splitEmails((string) ($routes[$form] ?? $routes['*'] ?? ''));
    }

    /** Is there any address for letters about requests? (The health screen warns when there is, but mail is not configured.) */
    public function hasMailRecipients(): bool
    {
        foreach ((array) ($this->load()[Setting::ALL]['itk:mailRoutes'] ?? []) as $list) {
            if ([] !== self::splitEmails((string) $list)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the addresses of a list written with commas, semicolons or line breaks, lower case, without repeats */
    public static function splitEmails(string $list): array
    {
        $out = [];
        foreach (\preg_split('/[\s,;]+/', $list) ?: [] as $one) {
            $one = \mb_strtolower(\trim($one));
            if ('' !== $one) {
                $out[$one] = $one;
            }
        }

        return \array_values($out);
    }

    /**
     * Chats from the bundle option `telegram.chat_ids` (config / ITK_TELEGRAM_CHAT_IDS): they receive the requests when Settings → Уведомления has none.
     *
     * @return list<string>
     */
    public function fallbackChatIds(): array
    {
        return self::splitIds($this->fallbackChatIds);
    }

    /**
     * The same for MAX: the bundle option `max.chat_ids` (config / ITK_MAX_CHAT_IDS).
     *
     * @return list<string>
     */
    public function maxFallbackChatIds(): array
    {
        return self::splitIds($this->maxFallbackChatIds);
    }

    /** Is there a MAX chat outside the settings? The admin gets only this yes / no (`maxFallback`). */
    public function hasMaxFallbackChat(): bool
    {
        return [] !== $this->maxFallbackChatIds();
    }

    /** Is there a chat outside the settings? The admin gets only this yes / no (`tgFallback`), never the ids. */
    public function hasFallbackChat(): bool
    {
        return [] !== $this->fallbackChatIds();
    }

    public function isTelegramOn(): bool
    {
        return (bool) ($this->load()[Setting::ALL]['itk:tgOn'] ?? true);
    }

    public function isMaxOn(): bool
    {
        return (bool) ($this->load()[Setting::ALL]['itk:maxOn'] ?? true);
    }

    public function acceptsRequests(): bool
    {
        return (bool) ($this->load()[Setting::ALL]['forms'] ?? true);
    }

    /** '' when the value can be the text of robots.txt / llms.txt (null is fine: it means «the standard one»), else the reason. */
    public static function crawlerTextError(mixed $value): string
    {
        if (null !== $value && !\is_string($value)) {
            return I18n::t('content.crawler_text_not_text');
        }

        return \strlen((string) $value) > self::CRAWLER_TEXT_MAX ? I18n::t('content.crawler_text_too_long', ['kb' => self::CRAWLER_TEXT_MAX / 1024]) : '';
    }

    /** The text as it is kept: line breaks as \n, no blank edges; null is ''. */
    public static function crawlerText(mixed $value): string
    {
        return \trim(\str_replace(["\r\n", "\r"], "\n", (string) $value));
    }

    public static function chatIdError(string $v): string
    {
        $s = \trim($v);
        if ('' === $s) {
            return '';
        }

        return \preg_match('/^-?\d{5,20}$/', $s) || \preg_match('/^@[A-Za-z][A-Za-z0-9_]{4,31}$/', $s)
            ? ''
            : I18n::t('content.settings_chat_id_hint');
    }

    /** '' when the value is a MAX chat id: a whole number, negative for a group (the bot sends by number, there are no channel names). */
    public static function maxChatIdError(string $v): string
    {
        $s = \trim($v);
        if ('' === $s) {
            return '';
        }

        return \preg_match('/^-?\d{1,20}$/', $s) && false !== \filter_var($s, \FILTER_VALIDATE_INT) ? '' : I18n::t('content.settings_max_chat_id_hint');
    }

    /** @return array<string, string> */
    private function checkMaxRoutes(mixed $value): array
    {
        $out = [];
        foreach (\is_array($value) ? $value : [] as $form => $chat) {
            $chat = \trim(\is_scalar($chat) ? (string) $chat : '');
            foreach (\explode(',', $chat) as $one) {
                if ('' !== self::maxChatIdError($one)) {
                    throw ContentException::invalid(I18n::t('content.settings_max_chat_ids_check'), ['maxRoutes' => self::maxChatIdError($one)]);
                }
            }
            $out[(string) $form] = $chat;
        }

        return $out;
    }

    /** @return array<string, string> */
    private function checkRoutes(mixed $value): array
    {
        $out = [];
        foreach (\is_array($value) ? $value : [] as $form => $chat) {
            $chat = \trim((string) $chat);
            foreach (\explode(',', $chat) as $one) {
                if ('' !== self::chatIdError($one)) {
                    throw ContentException::invalid(I18n::t('content.settings_chat_ids_check'), ['tgRoutes' => self::chatIdError($one)]);
                }
            }
            $out[(string) $form] = $chat;
        }

        return $out;
    }

    /**
     * @return array<string, string> form key (or '*') => addresses written as «a@x.com, b@x.com»
     */
    private function checkMailRoutes(mixed $value): array
    {
        $out = [];
        foreach (\is_array($value) ? $value : [] as $form => $list) {
            $emails = self::splitEmails(\is_scalar($list) ? (string) $list : '');
            if (\count($emails) > 10) {
                throw ContentException::invalid(I18n::t('content.settings_mail_limit'), ['mailRoutes' => I18n::t('content.settings_mail_limit')]);
            }
            foreach ($emails as $email) {
                if (\strlen($email) > 180 || false === \filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                    throw ContentException::invalid(I18n::t('content.settings_mail_invalid', ['email' => $email]), ['mailRoutes' => I18n::t('content.settings_mail_invalid', ['email' => $email])]);
                }
            }
            if ([] !== $emails) {
                $out[(string) $form] = \implode(', ', $emails);
            }
        }

        return $out;
    }

    private function storageKey(string $key, bool $notifications = false): string
    {
        return \in_array($key, self::NOTIFICATION_KEYS, true) && ($notifications || null === $this->schema->settingsField($key)) ? 'itk:' . $key : $key;
    }

    /** @return array<string, array<string, mixed>> */
    private function load(): array
    {
        if (null === $this->loaded) {
            $this->loaded = [];
            foreach ($this->settings->findAll() as $s) {
                $this->loaded[$s->locale][$s->key] = $s->value;
            }
        }

        return $this->loaded;
    }
}
