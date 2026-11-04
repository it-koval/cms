<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\Setting;

/**
 * How the last attempt to send a letter ended: what «Состояние сайта» and «Настройки → Почта» tell («последнее письмо ушло» / «последняя отправка не удалась: …»).
 * Kept as the setting `mailStatus`; the error text is already cleaned of passwords. Writing it must never break sending, so a failure here is swallowed (and, written with SQL, cannot close the entity manager).
 */
final class MailStatus
{
    public const KEY = 'mailStatus';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function sent(): void
    {
        $this->write(['okAt' => \date(\DATE_ATOM), 'error' => null, 'errorAt' => null, 'kind' => null]);
    }

    public function failed(string $kind, string $error): void
    {
        $this->write(['okAt' => $this->get()['okAt'], 'error' => \mb_substr($error, 0, 500), 'errorAt' => \date(\DATE_ATOM), 'kind' => $kind]);
    }

    /** @return array{okAt: ?string, error: ?string, errorAt: ?string, kind: ?string} */
    public function get(): array
    {
        try {
            $raw = $this->db()->fetchOne('SELECT setting_value FROM itk_setting WHERE setting_key = ? AND locale = ?', [self::KEY, Setting::ALL]);
            $v = \is_string($raw) ? \json_decode($raw, true) : null;
        } catch (\Throwable) {
            $v = null;
        }
        $v = \is_array($v) ? $v : [];

        return [
            'okAt' => isset($v['okAt']) ? (string) $v['okAt'] : null,
            'error' => isset($v['error']) ? (string) $v['error'] : null,
            'errorAt' => isset($v['errorAt']) ? (string) $v['errorAt'] : null,
            'kind' => isset($v['kind']) ? (string) $v['kind'] : null,
        ];
    }

    /**
     * Written with plain SQL, not through the entity manager: a failure of the database here would close the manager, and the work that follows (in a request or in a worker message)
     * would fail with it. It reads the same way, so a value written a moment ago is not hidden by an entity the manager has remembered.
     *
     * @param array<string, mixed> $value
     */
    private function write(array $value): void
    {
        try {
            $json = \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
            $db = $this->db();
            $changed = $db->executeStatement('UPDATE itk_setting SET setting_value = ? WHERE setting_key = ? AND locale = ?', [$json, self::KEY, Setting::ALL]);
            if (0 === $changed) {
                try {
                    $db->executeStatement('INSERT INTO itk_setting (setting_key, locale, setting_value) VALUES (?, ?, ?)', [self::KEY, Setting::ALL, $json]);
                } catch (UniqueConstraintViolationException) {
                    // the row is there and holds this very value (an update that changes nothing counts zero rows)
                }
            }
        } catch (\Throwable) {
            // the state is a convenience; the letter matters more
        }
    }

    private function db(): Connection
    {
        return $this->em->getConnection();
    }
}
