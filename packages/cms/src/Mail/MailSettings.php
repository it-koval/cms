<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Setting;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Security\TwoFactor\SecretBox;

/**
 * «Настройки → Почта»: the mail server the site sends letters through. One setting `mail` (shared by all languages), kept outside the settings groups
 * of the schema on purpose: the generic settings API never hands it out, so the password cannot leave through it.
 *
 * The password is stored sealed (libsodium secretbox, key from APP_SECRET: Security\TwoFactor\SecretBox). It is never returned: view() says only
 * `passwordSet: true|false`. On save an empty or absent `password` keeps the current one; `clearPassword: true` removes it.
 * The saved password stays bound to the server it was saved for: when the host or the login changes, a new password must come with the change (422 otherwise),
 * so nobody who may edit the settings can point the saved password at a server of his own.
 */
final class MailSettings
{
    public const KEY = 'mail';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingRepository $settings,
        private readonly SecretBox $box,
    ) {
    }

    /** The settings for building a connection (the password in plain text: never pass it on to a response). */
    public function config(): MailConfig
    {
        $s = $this->stored();
        $encryption = \in_array($s['encryption'] ?? null, MailConfig::ENCRYPTIONS, true) ? (string) $s['encryption'] : MailConfig::STARTTLS;
        $port = (int) ($s['port'] ?? 0);

        return new MailConfig(
            enabled: (bool) ($s['enabled'] ?? false),
            host: \trim((string) ($s['host'] ?? '')),
            port: $port > 0 && $port <= 65535 ? $port : MailConfig::DEFAULT_PORTS[$encryption],
            encryption: $encryption,
            username: (string) ($s['username'] ?? ''),
            password: $this->password($s),
            fromAddress: \trim((string) ($s['fromAddress'] ?? '')),
            fromName: \trim((string) ($s['fromName'] ?? '')),
        );
    }

    /**
     * What the admin gets: every field but the password, and whether one is saved.
     *
     * @return array<string, mixed>
     */
    public function view(): array
    {
        $c = $this->config();
        $s = $this->stored();

        return [
            'enabled' => $c->enabled,
            'host' => $c->host,
            'port' => $c->port,
            'encryption' => $c->encryption,
            'username' => $c->username,
            'passwordSet' => '' !== (string) ($s['password'] ?? ''),
            'fromAddress' => $c->fromAddress,
            'fromName' => $c->fromName,
        ];
    }

    /**
     * Saves the fields that came; the rest stay as they are.
     *
     * @param array<string, mixed> $input { enabled?, host?, port?, encryption?, username?, password?, clearPassword?, fromAddress?, fromName? }
     *
     * @return array<string, mixed> view()
     */
    public function save(#[\SensitiveParameter] array $input): array
    {
        $s = $this->stored();
        $errors = [];

        $enabled = \array_key_exists('enabled', $input) ? (bool) $input['enabled'] : (bool) ($s['enabled'] ?? false);
        $host = \array_key_exists('host', $input) ? \trim((string) $input['host']) : \trim((string) ($s['host'] ?? ''));
        $encryption = \array_key_exists('encryption', $input) ? (string) $input['encryption'] : (string) ($s['encryption'] ?? MailConfig::STARTTLS);
        $username = \array_key_exists('username', $input) ? (string) $input['username'] : (string) ($s['username'] ?? '');
        $fromAddress = \array_key_exists('fromAddress', $input) ? \trim((string) $input['fromAddress']) : \trim((string) ($s['fromAddress'] ?? ''));
        $fromName = \array_key_exists('fromName', $input) ? \trim((string) $input['fromName']) : \trim((string) ($s['fromName'] ?? ''));

        if (!\in_array($encryption, MailConfig::ENCRYPTIONS, true)) {
            $errors['encryption'] = I18n::t('http.mail_encryption_choose');
            $encryption = MailConfig::STARTTLS;
        }
        $rawPort = \array_key_exists('port', $input) ? $input['port'] : ($s['port'] ?? null);
        $port = null;
        if (null !== $rawPort && '' !== \trim((string) $rawPort)) {
            if (!\is_numeric($rawPort) || (float) (int) $rawPort !== (float) $rawPort || (int) $rawPort < 1 || (int) $rawPort > 65535) {
                $errors['port'] = I18n::t('http.mail_port_invalid');
            } else {
                $port = (int) $rawPort;
            }
        }
        $port ??= MailConfig::DEFAULT_PORTS[$encryption];

        if ('' !== $host && (\strlen($host) > 253 || !\preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/', $host))) {
            $errors['host'] = I18n::t('http.mail_host_invalid');
        }
        if (\mb_strlen($username) > 255 || \preg_match('/[\x00-\x1F\x7F]/', $username)) {
            $errors['username'] = I18n::t('http.mail_username_invalid');
        }
        if ('' !== $fromAddress && (\strlen($fromAddress) > 180 || false === \filter_var($fromAddress, \FILTER_VALIDATE_EMAIL))) {
            $errors['fromAddress'] = I18n::t('http.email_typo');
        }
        if (\mb_strlen($fromName) > 120 || \preg_match('/[\x00-\x1F\x7F]/', $fromName)) {
            $errors['fromName'] = I18n::t('http.mail_from_name_invalid');
        }

        $sealed = (string) ($s['password'] ?? '');
        if (!empty($input['clearPassword'])) {
            $sealed = '';
        }
        $password = $input['password'] ?? null;
        if (\is_string($password) && '' !== $password) {
            if (\mb_strlen($password) > 255 || \preg_match('/[\x00-\x1F\x7F]/', $password)) {
                $errors['password'] = I18n::t('http.mail_password_invalid');
            } else {
                $sealed = $this->box->seal($password);
            }
        }

        if (!MailConfig::canSendCredentials($encryption, '' !== $sealed)) {
            $errors['encryption'] = I18n::t('http.mail_password_requires_tls');
        }

        $moved = \strtolower($host) !== \strtolower(\trim((string) ($s['host'] ?? ''))) || $username !== (string) ($s['username'] ?? '');
        if ($moved && '' !== $sealed && !(\is_string($password) && '' !== $password) && !isset($errors['password'])) {
            $errors['password'] = I18n::t('http.mail_server_changed');
        }

        if ($enabled) {
            if ('' === $host) {
                $errors['host'] ??= I18n::t('http.mail_host_required');
            }
            if ('' === $fromAddress) {
                $errors['fromAddress'] ??= I18n::t('http.mail_from_required');
            }
        }
        if ([] !== $errors) {
            throw ContentException::invalid((string) \reset($errors), $errors);
        }

        $value = [
            'enabled' => $enabled, 'host' => $host, 'port' => $port, 'encryption' => $encryption, 'username' => $username,
            'password' => $sealed, 'fromAddress' => $fromAddress, 'fromName' => $fromName,
        ];
        $row = $this->settings->find(['key' => self::KEY, 'locale' => Setting::ALL]);
        if (null === $row) {
            $this->em->persist(new Setting(self::KEY, Setting::ALL, $value));
        } else {
            $row->value = $value;
        }
        $this->em->flush();

        return $this->view();
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        $row = $this->settings->find(['key' => self::KEY, 'locale' => Setting::ALL]);

        return null !== $row && \is_array($row->value) ? $row->value : [];
    }

    /** @param array<string, mixed> $s */
    private function password(#[\SensitiveParameter] array $s): string
    {
        $sealed = (string) ($s['password'] ?? '');

        // a password sealed under another APP_SECRET cannot be read: it counts as «not set» for the connection (the admin sees `passwordSet` and enters it again)
        return '' === $sealed ? '' : ($this->box->open($sealed) ?? '');
    }
}
