<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\DriverManager;
use Itk\Cms\Content\EditLock;
use Itk\Cms\I18n\I18n;

/**
 * SMTP has no idempotency key: an interrupted send must be inspected, never automatically repeated.
 *
 * @internal
 */
final class RequestMailCheckpoint
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public static function key(SendMail $mail): ?string
    {
        return SendMail::REQUEST === $mail->kind && null !== $mail->ref && '' !== $mail->ref
            ? self::recipientKey($mail->ref, $mail->to) : null;
    }

    public static function recipientKey(string $requestId, string $recipient): string
    {
        return \hash('sha256', $requestId . ':' . \strtolower(\trim($recipient)));
    }

    /** A retry may reconstruct only a letter whose previous attempt is known to have been rejected. */
    public function isRejected(string $requestId, string $recipient): bool
    {
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }

        return 'rejected' === $this->connection->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [self::recipientKey($requestId, $recipient)]);
    }

    /** Record an SMTP result independently verified by an operator; never modify an active sender. */
    public function resolve(string $requestId, string $recipient, bool $accepted, bool $legacyWarning = false): bool
    {
        $key = self::recipientKey($requestId, $recipient);
        $db = DriverManager::getConnection($this->connection->getParams(), $this->connection->getConfiguration());
        $db->setAutoCommit(true);
        try {
            if ($db instanceof PrimaryReadReplicaConnection) {
                $db->ensureConnectedToPrimary();
            }

            return EditLock::onConnection($db, 'request-mail', $key, static function () use ($db, $key, $accepted, $legacyWarning): bool {
                $updated = $db->executeStatement("UPDATE itk_request_mail_delivery SET state = ? WHERE delivery_key = ? AND state IN ('sending', 'unknown')", [$accepted ? 'done' : 'rejected', $key]);
                if (1 === $updated || !$accepted || !$legacyWarning) {
                    return 1 === $updated;
                }

                // The aggregate warning from before checkpoints can refer to a verified acceptance with no row yet.
                $db->executeStatement("INSERT INTO itk_request_mail_delivery (delivery_key, state) VALUES (?, 'done') ON CONFLICT (delivery_key) DO NOTHING", [$key]);

                return 'done' === $db->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [$key]);
            });
        } finally {
            $db->close();
        }
    }

    /** @param \Closure(): void $send */
    public function deliver(SendMail $mail, \Closure $send): void
    {
        $key = self::key($mail);
        if (null === $key) {
            $send();

            return;
        }
        // The checkpoint must survive rollback or a crash of the Messenger/HTTP caller.
        $db = DriverManager::getConnection($this->connection->getParams(), $this->connection->getConfiguration());
        $db->setAutoCommit(true);
        try {
            if ($db instanceof PrimaryReadReplicaConnection) {
                $db->ensureConnectedToPrimary();
            }
            EditLock::onConnection($db, 'request-mail', $key, static function () use ($db, $key, $send): void {
                $state = $db->fetchOne('SELECT state FROM itk_request_mail_delivery WHERE delivery_key = ?', [$key]);
                if ('done' === $state) {
                    return;
                }
                if (\in_array($state, ['sending', 'unknown'], true)) {
                    $db->executeStatement('UPDATE itk_request_mail_delivery SET state = ? WHERE delivery_key = ?', ['unknown', $key]);
                    throw new MailDeliveryUnknownException(I18n::t('http.mail_delivery_unknown'));
                }
                $db->executeStatement('INSERT INTO itk_request_mail_delivery (delivery_key, state) VALUES (?, ?) ON CONFLICT (delivery_key) DO UPDATE SET state = EXCLUDED.state', [$key, 'sending']);
                try {
                    $send();
                } catch (\Throwable $e) {
                    $safe = $e instanceof MailDeliveryException && $e->safeToRetry;
                    $db->executeStatement('UPDATE itk_request_mail_delivery SET state = ? WHERE delivery_key = ?', [$safe ? 'rejected' : 'unknown', $key]);
                    if ($safe) {
                        throw $e;
                    }
                    throw new MailDeliveryUnknownException(I18n::t('http.mail_delivery_unknown'));
                }
                $db->executeStatement('UPDATE itk_request_mail_delivery SET state = ? WHERE delivery_key = ?', ['done', $key]);
            });
        } finally {
            $db->close();
        }
    }
}
