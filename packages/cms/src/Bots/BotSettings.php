<?php

declare(strict_types=1);

namespace Itk\Cms\Bots;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Setting;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Security\TwoFactor\SecretBox;

/** @internal */
final class BotSettings
{
    public const TELEGRAM_KEY = 'itk:telegramBot';
    public const MAX_KEY = 'itk:maxBot';
    private const KEYS = ['telegram' => self::TELEGRAM_KEY, 'max' => self::MAX_KEY];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingRepository $settings,
        private readonly SecretBox $box,
    ) {
    }

    public function token(string $channel): ?string
    {
        $sealed = $this->sealed($channel);
        $token = '' === $sealed ? null : $this->box->open($sealed);

        return '' === $token ? null : $token;
    }

    public function unreadable(string $channel): bool
    {
        return '' !== $this->sealed($channel) && null === $this->token($channel);
    }

    /** @param array<string, mixed> $input */
    public function save(#[\SensitiveParameter] array $input): void
    {
        $errors = [];
        $changes = [];
        foreach (self::KEYS as $channel => $key) {
            if (!\array_key_exists($channel, $input)) {
                continue;
            }
            $value = $input[$channel];
            $error = I18n::t('telegram' === $channel ? 'http.bot_telegram_invalid' : 'http.bot_max_invalid');
            if (!\is_array($value) || (\array_key_exists('clear', $value) && !\is_bool($value['clear']))) {
                $errors[$channel] = $error;
                continue;
            }
            if (\array_key_exists('token', $value) && !\is_string($value['token'])) {
                $errors[$channel] = $error;
                continue;
            }
            $token = $value['token'] ?? '';
            if ('' !== $token && !self::valid($channel, $token)) {
                $errors[$channel] = $error;
                continue;
            }
            if (true === ($value['clear'] ?? false)) {
                $changes[$key] = '';
            } elseif ('' !== $token) {
                $changes[$key] = $this->box->seal($token);
            }
        }
        if ([] !== $errors) {
            throw ContentException::invalid((string) \reset($errors), $errors);
        }
        foreach ($changes as $key => $sealed) {
            $row = $this->settings->find(['key' => $key, 'locale' => Setting::ALL]);
            if (null === $row) {
                if ('' !== $sealed) {
                    $this->em->persist(new Setting($key, Setting::ALL, ['token' => $sealed]));
                }
            } else {
                $row->value = ['token' => $sealed];
            }
        }
        if ([] !== $changes) {
            $this->em->flush();
        }
    }

    private static function valid(string $channel, #[\SensitiveParameter] string $token): bool
    {
        return 'telegram' === $channel
            ? 1 === \preg_match('/^\d{5,20}:[A-Za-z0-9_-]{30,}$/D', $token)
            : \mb_strlen($token) <= 512 && 0 === \preg_match('/[\s\x00-\x1F\x7F]/u', $token);
    }

    private function sealed(string $channel): string
    {
        $key = self::KEYS[$channel] ?? throw new \InvalidArgumentException('Unknown bot channel');
        $row = $this->settings->find(['key' => $key, 'locale' => Setting::ALL]);
        $sealed = \is_array($row?->value) ? ($row->value['token'] ?? '') : '';

        return \is_string($sealed) ? $sealed : '';
    }
}
