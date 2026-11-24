<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Repository\RoleRepository;
use Itk\Cms\Repository\UserRepository;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\TwoFactor\TwoFactorService;

/**
 * Users and roles of the admin (Настройки → Пользователи и роли). Rules (mirror of admin/src/api/users.js):
 *  - nobody gives a role that holds more than his own, and nobody changes / blocks / deletes a person who has such a role (403);
 *  - the only active «Администратор» cannot lose the role, be blocked or deleted (409); you cannot change your own role (403), block or delete yourself (409);
 *  - built-in roles cannot be changed or deleted (403); a role with people is deleted only with `moveTo` (409 {users}).
 * A role can demand a second sign-in factor (`require2fa`, also for the built-in roles: it is not a right); an administrator can take a person's second factor
 * away (a lost phone): he sets it up again at the next sign-in.
 * When the site can send mail (Настройки → Почта, or MAILER_DSN) an invitation / password reset sends the person a link to set his own password (PasswordRecovery) and says so
 * only after the letter is in the queue (`emailed: true`; no password is returned). Without mail, or when the queue does not take the letter, the new one-time password
 * is handed back to the admin, who passes it on (returned once as `password`; with `emailError` when mail was tried).
 *
 * @internal
 */
final class UserAdmin
{
    public const LAST_ADMIN = 'http.users_last_admin';
    public const ROLE_TOO_BIG = 'http.users_role_too_big';
    public const USER_TOO_BIG = 'http.users_user_too_big';
    public const TWO_FACTOR_TOO_BIG = 'http.users_2fa_too_big';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly UserManager $manager,
        private readonly RoleResolver $resolver,
        private readonly Access $access,
        private readonly Schema $schema,
        private readonly TwoFactorService $factor,
        private readonly SiteMailer $mailer,
        private readonly PasswordRecovery $recovery,
    ) {
    }

    /* ---------- users ---------- */

    /** @return list<array<string, mixed>> */
    public function listUsers(): array
    {
        $roles = $this->roleNames();

        return \array_map(fn (User $u): array => $this->presentUser($u, $roles), $this->users->findBy([], ['createdAt' => 'ASC']));
    }

    /** @return array<string, mixed> */
    public function getUser(string $id): array
    {
        return $this->presentUser($this->user($id));
    }

    /**
     * @param array<string, mixed> $data { name, email, roleId }
     *
     * @return array<string, mixed> the user + `emailed: true` (a link went to his mail; 7 days) or `password` (one-time, show it to the admin) and `emailed: false` [+ `emailError`]
     */
    public function invite(array $data): array
    {
        $roleKey = (string) ($data['roleId'] ?? '');
        $role = $this->roles->find($roleKey);
        if (null !== $role) {
            $this->mustHold($role, self::ROLE_TOO_BIG);
        }
        $password = $this->newPassword();
        try {
            $user = $this->manager->create((string) ($data['email'] ?? ''), (string) ($data['name'] ?? ''), $roleKey, $password, User::INVITED);
        } catch (ContentException $e) {
            // the taken address is told from the data, not from the wording of the error
            $taken = null !== $this->users->findOneByEmail(\mb_strtolower(\trim((string) ($data['email'] ?? ''))));
            throw $taken ? ContentException::conflict($e->getMessage(), (array) $e->details()) : $e;
        }

        $out = $this->presentUser($user);
        $error = null;
        if ($this->mailer->isConfigured()) {
            try {
                $this->recovery->sendLink($user, SendMail::INVITE, PasswordRecovery::INVITE_TTL);

                return $out + ['emailed' => true]; // his password stays unknown to everybody until he sets one with the link
            } catch (ContentException $e) {
                $error = $e->getMessage();
            }
        }

        return $out + ['password' => $password, 'emailed' => false] + (null === $error ? [] : ['emailError' => $error]);
    }

    /**
     * @param array<string, mixed> $patch { name?, email?, roleId? }
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $patch): array
    {
        $u = $this->user($id);
        $current = $this->roles->find($u->role);
        if (null !== $current) {
            $this->mustHold($current, self::USER_TOO_BIG);
        }
        $name = \array_key_exists('name', $patch) ? \trim((string) $patch['name']) : $u->name;
        $email = \array_key_exists('email', $patch) ? \mb_strtolower(\trim((string) $patch['email'])) : $u->email;
        $roleKey = \array_key_exists('roleId', $patch) ? (string) $patch['roleId'] : $u->role;

        $details = [];
        if ('' === $name) {
            $details['name'] = I18n::t('http.name_required');
        }
        if (!\preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
            $details['email'] = I18n::t('' === $email ? 'http.email_required' : 'http.email_typo');
        } else {
            $other = $this->users->findOneByEmail($email);
            if (null !== $other && $other->id !== $u->id) {
                throw ContentException::conflict(I18n::t('http.email_taken'), ['email' => I18n::t('http.email_taken')]);
            }
        }
        $role = $this->roles->find($roleKey);
        if (null === $role) {
            $details['roleId'] = I18n::t('http.role_required');
        }
        if ([] !== $details) {
            throw ContentException::invalid((string) \reset($details), $details);
        }
        if ($roleKey !== $u->role) {
            $this->mustHold($role, self::ROLE_TOO_BIG);
            if ($this->isMe($u)) {
                throw ContentException::forbidden(I18n::t('http.users_own_role'));
            }
            if (Role::ADMIN === $u->role && User::ACTIVE === $u->status && $this->isLastAdmin($u)) {
                throw ContentException::conflict(I18n::t(self::LAST_ADMIN));
            }
        }
        $emailChanged = $email !== $u->email;
        $u->name = $name;
        $u->email = $email;
        $u->role = $roleKey;
        $this->em->flush();
        if ($emailChanged) {
            $this->recovery->revokeLinks($u->id); // a letter that went to the old address (a mistyped one, say) must not set the password of this account
        }

        return $this->presentUser($u);
    }

    /**
     * Password for another person. With mail: a link to set his own password goes to his e-mail (24 hours; an invited person who has not signed in yet gets the invitation again, 7 days)
     * and the old password stays until he sets a new one → `emailed: true`. Without mail, or when the queue does not take the letter: a new one-time password, which the admin passes on;
     * the person changes it in his profile.
     * Not for yourself: that is «Профиль → Сменить пароль» (the current password is asked).
     *
     * @return array{ok: bool, email: string, invited: bool, emailed: bool, password?: string, emailError?: string}
     */
    public function resetPassword(string $id): array
    {
        $u = $this->user($id);
        $this->mustHoldUser($u);
        if ($this->isMe($u)) {
            throw ContentException::forbidden(I18n::t('http.users_own_password'));
        }
        $invited = User::INVITED === $u->status;
        $error = null;
        if ($this->mailer->isConfigured()) {
            try {
                $this->recovery->sendLink($u, $invited ? SendMail::INVITE : SendMail::RESET, $invited ? PasswordRecovery::INVITE_TTL : PasswordRecovery::ADMIN_RESET_TTL);

                return ['ok' => true, 'email' => $u->email, 'invited' => $invited, 'emailed' => true];
            } catch (ContentException $e) {
                $error = $e->getMessage();
            }
        }
        $password = $this->newPassword();
        $this->manager->setPassword($u, $password);

        return ['ok' => true, 'email' => $u->email, 'invited' => $invited, 'emailed' => false, 'password' => $password] + (null === $error ? [] : ['emailError' => $error]);
    }

    /**
     * Takes the second sign-in factor away from a person who lost the phone and the recovery codes. Not for yourself: that is «Профиль → Двухэтапный вход».
     *
     * @return array<string, mixed>
     */
    public function resetTwoFactor(string $id): array
    {
        $u = $this->user($id);
        $this->mustHoldUser($u);
        if ($this->isMe($u)) {
            throw ContentException::forbidden(I18n::t('http.users_own_2fa'));
        }
        $this->factor->reset($u);

        return $this->presentUser($u);
    }

    /** @return array<string, mixed> */
    public function setBlocked(string $id, bool $blocked): array
    {
        $u = $this->user($id);
        $this->mustHoldUser($u);
        if ($blocked) {
            if ($this->isMe($u)) {
                throw ContentException::conflict(I18n::t('http.users_block_self'));
            }
            if (Role::ADMIN === $u->role && User::ACTIVE === $u->status && $this->isLastAdmin($u)) {
                throw ContentException::conflict(I18n::t(self::LAST_ADMIN));
            }
            $u->status = User::BLOCKED;
        } else {
            $u->status = null !== $u->lastSignInAt ? User::ACTIVE : User::INVITED;
        }
        $this->em->flush();
        if ($blocked) {
            $this->recovery->revokeLinks($u->id);
        }

        return $this->presentUser($u);
    }

    public function removeUser(string $id): void
    {
        $u = $this->user($id);
        $this->mustHoldUser($u);
        if ($this->isMe($u)) {
            throw ContentException::conflict(I18n::t('http.users_delete_self'));
        }
        if (Role::ADMIN === $u->role && User::ACTIVE === $u->status && $this->isLastAdmin($u)) {
            throw ContentException::conflict(I18n::t(self::LAST_ADMIN));
        }
        $this->em->remove($u);
        $this->em->flush();
    }

    /* ---------- roles ---------- */

    /** @return list<array<string, mixed>> */
    public function listRoles(): array
    {
        $out = [];
        foreach ($this->roles->findAll() as $r) {
            $out[] = $this->presentRole($r);
        }
        \usort($out, static fn (array $a, array $b): int => [$b['builtin'], $a['name']] <=> [$a['builtin'], $b['name']]);

        return $out;
    }

    /** @return array<string, mixed> */
    public function getRole(string $key): array
    {
        return $this->presentRole($this->role($key));
    }

    /**
     * @param array<string, mixed> $data { name, description?, copyOf? }
     *
     * @return array<string, mixed>
     */
    public function createRole(array $data): array
    {
        $name = \trim((string) ($data['name'] ?? ''));
        $this->checkRoleName($name, null);
        $perms = [];
        $templates = null;
        $copyOf = (string) ($data['copyOf'] ?? '');
        if ('' !== $copyOf) {
            $from = $this->resolver->resolve($this->role($copyOf));
            $mine = $this->myRights();
            foreach ($from['perms'] as $area => $actions) { // a copy never has more than the person who makes it
                $kept = \array_values(\array_intersect($actions, $mine['perms'][$area] ?? []));
                if ([] !== $kept) {
                    $perms[$area] = $kept;
                }
            }
            $templates = isset($perms['pages']) ? $this->copiedTemplates($from['templates'], $this->myTemplates()) : null; // a copy without pages keeps no template list
            if ([] === $templates) { // no template of the source is the author's: the copy gets no pages at all
                unset($perms['pages']);
                $templates = null;
            }
        }
        $role = new Role('role-' . \bin2hex(\random_bytes(4)), $name);
        $role->description = \mb_substr(\trim((string) ($data['description'] ?? '')), 0, 500);
        $role->perms = $perms;
        $role->templates = $templates;
        $this->em->persist($role);
        $this->em->flush();

        return $this->presentRole($role);
    }

    /**
     * @param array<string, mixed> $patch { name?, description?, perms?, templates?, require2fa? }
     *
     * @return array<string, mixed>
     */
    public function updateRole(string $key, array $patch): array
    {
        $role = $this->role($key);
        if ($role->builtin) {
            // the only thing a built-in role lets an administrator change: whether it needs a second factor
            if (!\array_key_exists('require2fa', $patch) || [] !== \array_diff_key($patch, ['require2fa' => true])) {
                throw ContentException::forbidden(I18n::t('http.roles_builtin_edit'));
            }
            $this->setRequireTwoFactor($role, (bool) $patch['require2fa']);
            $this->em->flush();

            return $this->presentRole($role);
        }
        $name = \array_key_exists('name', $patch) ? \trim((string) $patch['name']) : $role->name;
        $this->checkRoleName($name, $role->key, $role->name);
        if (\array_key_exists('templates', $patch) && null !== $patch['templates'] && [] === (array) $patch['templates']) {
            throw ContentException::invalid(I18n::t('http.roles_templates_required'), ['templates' => I18n::t('http.roles_templates_pick')]);
        }
        // a role without «Страницы» holds no template, whatever its list says (null included): templates it gets with pages are the editor's to give
        $hadPages = [] !== ($role->perms['pages'] ?? []);
        $heldTemplates = $role->templates;
        $templates = $role->templates;
        if (\array_key_exists('templates', $patch)) {
            $templates = null === $patch['templates'] ? null : \array_values(\array_filter(\array_map('strval', (array) $patch['templates']), fn (string $k): bool => null !== $this->schema->template($k)));
            if ($hadPages) {
                $templates = $this->narrowedTemplates($role->templates, $templates);
            }
        }
        if (\array_key_exists('perms', $patch)) {
            $mine = $this->myRights();
            $held = $role->perms;
            $perms = [];
            foreach ($this->schema->permissionAreas() as $a) {
                $asked = [];
                foreach ((array) (((array) $patch['perms'])[$a['key']] ?? []) as $action) {
                    // a right can be added only when the person has it himself (on «Страницы»: on every template the role reaches); a right the role already holds can stay
                    $holds = \in_array($action, (array) ($held[$a['key']] ?? []), true);
                    $gives = \in_array($action, $mine['perms'][$a['key']] ?? [], true) && ('pages' !== $a['key'] || !$hadPages || self::within($templates, $this->myTemplates()));
                    if (\in_array($action, $a['actions'], true) && ($holds || $gives)) {
                        $asked[] = $action;
                    }
                }
                $acts = \array_values(\array_intersect(RoleResolver::closeActions($asked), $a['actions']));
                if ([] !== $acts) {
                    $perms[$a['key']] = $acts;
                }
            }
            $role->perms = $perms;
        }
        if (!$hadPages && isset($role->perms['pages'])) {
            $templates = $this->narrowedTemplates([], $templates); // pages given now: the list the role had without them is cut to the editor's own
        }
        if ($hadPages && !self::within($templates, $heldTemplates) && [] !== \array_diff($role->perms['pages'] ?? [], $this->myRights()['perms']['pages'] ?? [])) {
            // a template is added only by someone who holds every action of the role on «Страницы»: otherwise its holders would publish or delete where he cannot
            $templates = null === $templates ? $heldTemplates : \array_values(\array_filter($templates, static fn (string $k): bool => null === $heldTemplates || \in_array($k, $heldTemplates, true)));
        }
        if (isset($role->perms['pages']) && [] === $templates) {
            throw ContentException::invalid(I18n::t('http.roles_templates_required'), ['templates' => I18n::t('http.roles_templates_pick')]);
        }
        $role->templates = $templates;
        if (\array_key_exists('require2fa', $patch)) {
            $this->setRequireTwoFactor($role, (bool) $patch['require2fa']);
        }
        $role->name = $name;
        if (\array_key_exists('description', $patch)) {
            $role->description = \mb_substr(\trim((string) $patch['description']), 0, 500);
        }
        $this->em->flush();

        return $this->presentRole($role);
    }

    /** @return array{ok: bool, moved: int} */
    public function removeRole(string $key, ?string $moveTo = null): array
    {
        $role = $this->role($key);
        if ($role->builtin) {
            throw ContentException::forbidden(I18n::t('http.roles_builtin_delete'));
        }
        $holders = $this->users->findBy(['role' => $key]);
        if ([] !== $holders) {
            $this->mustHold($role, self::USER_TOO_BIG);
            if (null === $moveTo || '' === $moveTo) {
                throw ContentException::conflict(I18n::t('http.roles_has_users'), ['users' => \count($holders)]);
            }
            $target = $this->roles->find($moveTo);
            if ($moveTo === $key || null === $target) {
                throw ContentException::invalid(I18n::t('http.roles_pick_target'));
            }
            $this->mustHold($target, self::ROLE_TOO_BIG);
            foreach ($holders as $u) {
                $u->role = $moveTo;
            }
        }
        $this->em->remove($role);
        $this->em->flush();

        return ['ok' => true, 'moved' => \count($holders)];
    }

    /* ---------- shapes ---------- */

    /**
     * @param array<string, string>|null $roleNames key => name
     *
     * @return array<string, mixed>
     */
    public function presentUser(User $u, ?array $roleNames = null): array
    {
        $roleNames ??= $this->roleNames();

        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'roleId' => $u->role, 'roleName' => $roleNames[$u->role] ?? I18n::t('http.no_role'),
            'status' => $u->status, 'twoFactor' => $this->factor->isEnabled($u),
            'lastSignIn' => $u->lastSignInAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'createdAt' => $u->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /** @return array<string, mixed> { id, name, description, builtin, preset, perms, templates, require2fa, users } rights already worked out */
    public function presentRole(Role $r): array
    {
        $rights = $this->resolver->resolve($r);

        return [
            'id' => $r->key, 'name' => RoleLabel::name($r), 'description' => RoleLabel::description($r), 'builtin' => $r->builtin, 'preset' => $r->builtin ? $r->key : null,
            'perms' => $rights['perms'] ?: new \stdClass(), 'templates' => $rights['templates'], 'require2fa' => $r->require2fa,
            'users' => $this->users->count(['role' => $r->key]),
        ];
    }

    /* ---------- internals ---------- */

    private function user(string $id): User
    {
        return $this->users->find($id) ?? throw ContentException::notFound(I18n::t('http.users_not_found'));
    }

    private function role(string $key): Role
    {
        return $this->roles->find($key) ?? throw ContentException::notFound(I18n::t('http.roles_not_found'));
    }

    /** @return array<string, string> */
    private function roleNames(): array
    {
        $out = [];
        foreach ($this->roles->findAll() as $r) {
            $out[$r->key] = RoleLabel::name($r);
        }

        return $out;
    }

    /** @return array{perms: array<string, list<string>>, templates: list<string>|null} */
    private function myRights(): array
    {
        $me = $this->access->user();

        return null === $me ? ['perms' => [], 'templates' => []] : $this->resolver->rightsOf($me);
    }

    /**
     * The page templates the person holds himself: null = every one. Without any right on «Страницы» he holds none, whatever his role says about templates
     * (a role without pages keeps templates null, and null must not let him give every template to a role that has pages).
     *
     * @return list<string>|null
     */
    private function myTemplates(): ?array
    {
        $mine = $this->myRights();

        return [] === ($mine['perms']['pages'] ?? []) ? [] : $mine['templates'];
    }

    /**
     * Templates a role may get from the person who edits it: he can add only what he holds himself, a template the role already holds can stay.
     *
     * @param list<string>|null $held what the role holds now ([] for a role without «Страницы»)
     * @param list<string>|null $asked null means every template
     *
     * @return list<string>|null
     */
    private function narrowedTemplates(?array $held, ?array $asked): ?array
    {
        $mine = $this->myTemplates();
        if (null === $asked && (null === $mine || null === $held)) {
            return null;
        }
        $asked ??= \array_map(strval(...), \array_keys($this->schema->templates()));

        return \array_values(\array_filter(
            \array_unique($asked),
            fn (string $k): bool => null !== $this->schema->template($k) && (null === $mine || null === $held || \in_array($k, $mine, true) || \in_array($k, $held, true)),
        )); // may be empty: a role that keeps «Страницы» is refused then (updateRole)
    }

    /**
     * @param list<string>|null $mine templates of the person who copies
     *
     * @return list<string>|null
     */
    /**
     * @param list<string>|null $templates null = every template
     * @param list<string>|null $mine null = every template
     */
    private static function within(?array $templates, ?array $mine): bool
    {
        return null === $mine || (null !== $templates && [] === \array_diff($templates, $mine));
    }

    private function copiedTemplates(?array $source, ?array $mine): ?array
    {
        if (null === $mine) {
            return $source;
        }

        return null === $source ? $mine : \array_values(\array_intersect($source, $mine));
    }

    private function isMe(User $u): bool
    {
        return $this->access->user()?->id === $u->id;
    }

    private function isLastAdmin(User $u): bool
    {
        return $this->users->countActiveWithRole(Role::ADMIN) <= 1;
    }

    private function mustHold(Role $role, string $messageKey): void
    {
        if (!RoleResolver::covers($this->myRights(), $this->resolver->resolve($role))) {
            throw ContentException::forbidden(I18n::t($messageKey));
        }
    }

    private function setRequireTwoFactor(Role $role, bool $on): void
    {
        if ($on !== $role->require2fa) {
            $this->mustHold($role, self::TWO_FACTOR_TOO_BIG);
            $role->require2fa = $on;
        }
    }

    private function mustHoldUser(User $u): void
    {
        $role = $this->roles->find($u->role);
        if (null !== $role) {
            $this->mustHold($role, self::USER_TOO_BIG);
        }
    }

    /**
     * @param string|null $current the stored name of the role being saved; an unchanged name is checked against stored names only,
     *                             so a custom role named like a translated built-in one stays editable
     */
    private function checkRoleName(string $name, ?string $exceptKey, ?string $current = null): void
    {
        if ('' === $name) {
            throw ContentException::invalid(I18n::t('http.roles_name_required'), ['name' => I18n::t('http.roles_name_required')]);
        }
        if (\mb_strlen($name) > 40) {
            throw ContentException::invalid(I18n::t('http.roles_name_too_long'), ['name' => I18n::t('http.roles_name_too_long')]);
        }
        $renamed = null === $current || \mb_strtolower($name) !== \mb_strtolower($current);
        foreach ($this->roles->findAll() as $r) {
            $names = $renamed ? self::namesOf($r) : [\mb_strtolower($r->name)];
            if ($r->key !== $exceptKey && \in_array(\mb_strtolower($name), $names, true)) {
                throw ContentException::conflict(I18n::t('http.roles_name_taken'), ['name' => I18n::t('http.roles_name_taken')]);
            }
        }
    }

    /**
     * Every name the role may be seen under: the stored one, the one on the screen in this language and, for a built-in role, its name in every language.
     *
     * @return list<string> lower-cased
     */
    private static function namesOf(Role $r): array
    {
        $names = [$r->name, RoleLabel::name($r)];
        if ($r->builtin && \in_array($r->key, RoleLabel::BUILTIN, true)) {
            $names = [...$names, ...I18n::variants(RoleLabel::nameKey($r->key))];
        }

        return \array_values(\array_unique(\array_map(\mb_strtolower(...), $names)));
    }

    private function newPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; ++$i) {
            $out .= $alphabet[\random_int(0, \strlen($alphabet) - 1)];
        }

        return $out;
    }
}
