<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Doctrine\DBAL\Connection;
use Itk\Cms\Notification\Notification;
use Itk\Cms\Notification\NotificationInbox;
use Itk\Cms\Repository\RequestRepository;

/** @internal */
final class RequestMailWarnings
{
    public function __construct(
        private readonly Connection $db,
        private readonly NotificationInbox $inbox,
        private readonly RequestRepository $requests,
    ) {
    }

    public function hasLegacyWarning(string $requestId): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM itk_notification WHERE dedupe_key IN (?, ?) LIMIT 1', self::legacyKeys($requestId))
            && null !== $this->requests->find((int) $requestId);
    }

    public function accepted(string $requestId, string $recipient): void
    {
        $this->inbox->resolve(MailFailureListener::requestKey($requestId, $recipient));
        // 0.2.2 never persisted the complete recipient list. Changed routes and done checkpoints cannot prove an aggregate is settled.
    }

    /** An operator has checked every historical recipient; this does not resolve any individual delivery. */
    public function resolveLegacy(string $requestId): bool
    {
        if (!$this->hasLegacyWarning($requestId)) {
            return false;
        }

        return 0 < $this->db->executeStatement('DELETE FROM itk_notification WHERE dedupe_key IN (?, ?)', self::legacyKeys($requestId));
    }

    /** @return array{string, string} */
    private static function legacyKeys(string $requestId): array
    {
        return ['mail_failed:' . $requestId, Notification::MAIL_FAILED . ':' . $requestId];
    }
}
