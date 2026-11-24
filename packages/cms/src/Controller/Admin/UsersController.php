<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\UserAdmin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Users and roles (rights: area `set:users`). Rules and answers: see UserAdmin.
 *   GET users   [{ id, name, email, roleId, roleName, status 'active'|'invited'|'blocked', lastSignIn, createdAt }]    GET users/{id}
 *   POST users { name, email, roleId }  (201, user + `emailed: true` when a link went to his mail, else a one-time `password` to hand over [+ `emailError` when mail was tried])     PATCH users/{id} { name?, email?, roleId? }
 *   POST users/{id}/reset-password   { ok, email, invited, emailed, password? }   with mail: a link goes to the person (`emailed: true`, no password, the old one works until he sets a new one); without mail: a new one-time password, shown to the admin once; 403 for yourself (the profile has its own form: POST me/password)      POST users/{id}/block | unblock   user      DELETE users/{id}   { ok }
 *   GET roles   [{ id, name, description, builtin, preset, perms, templates, require2fa, users }]     GET roles/{key}     POST roles { name, description?, copyOf? } (201)
 *   PATCH roles/{key} { name?, description?, perms?, templates?, require2fa? }     DELETE roles/{key}?moveTo=<role>   { ok, moved }
 *   POST users/{id}/reset-2fa  user   (takes the second sign-in factor away; he sets it up again at the next sign-in; not for yourself)
 *   `users` carry `twoFactor` (bool), `roles` carry `require2fa`; PATCH roles/{key} { require2fa } also works for built-in roles (the only change they allow).
 * GET mail-ready   { ready }   can the site send mail (then «Пригласить» and «Сбросить пароль» send a link, `emailed: true`, and return no password)
 * Aliases for the mock's names: POST users/{id}/password-reset, PUT users/{id}/blocked { blocked }.
 *
 * @internal
 */
#[AsController]
#[Route('/api', name: 'itk_api_users_')]
final class UsersController extends AbstractApiController
{
    private const AREA = 'set:users';

    public function __construct(
        private readonly UserAdmin $admin,
        private readonly Access $access,
        private readonly SiteMailer $mailer,
    ) {
    }

    #[Route('/mail-ready', name: 'mail_ready', methods: ['GET'])]
    public function mailReady(): JsonResponse
    {
        $this->access->require(self::AREA, 'view');

        return $this->json(['ready' => $this->mailer->isConfigured()]);
    }

    #[Route('/users', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->access->require(self::AREA, 'view');

        return $this->json($this->admin->listUsers());
    }

    #[Route('/users', name: 'invite', methods: ['POST'])]
    public function invite(Request $request): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->admin->invite($this->body($request)), 201);
    }

    #[Route('/users/{id}', name: 'get', requirements: ['id' => '[^/]+'], methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $this->access->require(self::AREA, 'view');

        return $this->json($this->admin->getUser($id));
    }

    #[Route('/users/{id}', name: 'update', requirements: ['id' => '[^/]+'], methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->admin->update($id, $this->body($request)));
    }

    #[Route('/users/{id}', name: 'remove', requirements: ['id' => '[^/]+'], methods: ['DELETE'])]
    public function remove(string $id): JsonResponse
    {
        $this->access->require(self::AREA, 'delete');
        $this->admin->removeUser($id);

        return $this->json(['ok' => true]);
    }

    #[Route('/users/{id}/reset-password', name: 'reset_password', methods: ['POST'])]
    #[Route('/users/{id}/password-reset', name: 'password_reset', methods: ['POST'])]
    public function resetPassword(string $id): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->admin->resetPassword($id));
    }

    #[Route('/users/{id}/reset-2fa', name: 'reset_2fa', methods: ['POST'])]
    public function resetTwoFactor(string $id): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->admin->resetTwoFactor($id));
    }

    #[Route('/users/{id}/block', name: 'block', methods: ['POST'])]
    public function block(string $id): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->admin->setBlocked($id, true));
    }

    #[Route('/users/{id}/unblock', name: 'unblock', methods: ['POST'])]
    public function unblock(string $id): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->admin->setBlocked($id, false));
    }

    #[Route('/users/{id}/blocked', name: 'blocked', methods: ['PUT'])]
    public function blocked(Request $request, string $id): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->admin->setBlocked($id, (bool) ($this->body($request)['blocked'] ?? false)));
    }

    #[Route('/roles', name: 'roles', methods: ['GET'])]
    public function roles(): JsonResponse
    {
        $this->access->require(self::AREA, 'view');

        return $this->json($this->admin->listRoles());
    }

    #[Route('/roles', name: 'role_create', methods: ['POST'])]
    public function createRole(Request $request): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->admin->createRole($this->body($request)), 201);
    }

    #[Route('/roles/{key}', name: 'role_get', requirements: ['key' => '[^/]+'], methods: ['GET'])]
    public function role(string $key): JsonResponse
    {
        $this->access->require(self::AREA, 'view');

        return $this->json($this->admin->getRole($key));
    }

    #[Route('/roles/{key}', name: 'role_update', requirements: ['key' => '[^/]+'], methods: ['PATCH'])]
    public function updateRole(Request $request, string $key): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->admin->updateRole($key, $this->body($request)));
    }

    #[Route('/roles/{key}', name: 'role_remove', requirements: ['key' => '[^/]+'], methods: ['DELETE'])]
    public function removeRole(Request $request, string $key): JsonResponse
    {
        $this->access->require(self::AREA, 'delete');
        $body = $this->body($request);
        $moveTo = $request->query->has('moveTo') ? $request->query->get('moveTo') : ($body['moveTo'] ?? null);

        return $this->json($this->admin->removeRole($key, \is_string($moveTo) && '' !== $moveTo ? $moveTo : null));
    }
}
