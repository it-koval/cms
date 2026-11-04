<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Notification\NotificationInbox;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The bell of the admin. Every signed-in person reads his own: a notification reaches him by its audience (a right or a role), and only if it was sent after he joined.
 *   GET  notifications?unread=1&limit=30&offset=0   { items: [{ id, type, title, body, link, level, payload, read, createdAt }], total, unread }   newest first; unread=1 = only the unread ones
 *   POST notifications/read { ids?: [..], all?: true }   { ok, unread }      marks as read; «all» = everything he can see.
 *
 * @internal
 */
#[AsController]
#[Route('/api/notifications', name: 'itk_api_notifications_')]
final class NotificationsController extends AbstractApiController
{
    public function __construct(
        private readonly Access $access,
        private readonly NotificationInbox $inbox,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->access->user() ?? throw new AccessDeniedException(I18n::t('http.sign_in_required'));
        $q = $request->query;

        return $this->json($this->inbox->list(
            $user,
            $this->access->viewer(),
            $q->getBoolean('unread'),
            $q->getInt('limit', 30),
            $q->getInt('offset', 0),
        ));
    }

    #[Route('/read', name: 'read', methods: ['POST'])]
    public function read(Request $request): JsonResponse
    {
        $user = $this->access->user() ?? throw new AccessDeniedException(I18n::t('http.sign_in_required'));
        $body = $this->body($request);
        $viewer = $this->access->viewer();
        $unread = true === ($body['all'] ?? false)
            ? $this->inbox->markAllRead($user, $viewer)
            : $this->inbox->markRead($user, $viewer, \array_map('intval', $this->ids($body['ids'] ?? [])));

        return $this->json(['ok' => true, 'unread' => $unread]);
    }
}
