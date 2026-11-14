<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Security\Permission;
use Itk\Cms\Security\PermissionVoter;
use Itk\Cms\Security\RoleResolver;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** Права проверяются на сервере: встроенные роли, шаблоны страниц, заблокированные пользователи. */
final class PermissionVoterTest extends KernelTestCase
{
    private PermissionVoter $voter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->voter = self::getContainer()->get(PermissionVoter::class);
    }

    private function vote(string $role, string $attribute, mixed $subject = null, string $status = User::ACTIVE): int
    {
        $user = new User($role . '@example.test', $role, $role);
        $user->status = $status;

        return $this->voter->vote(new UsernamePasswordToken($user, 'itk_admin', []), $subject, [$attribute]);
    }

    private function allowed(string $role, string $area, string $action, ?string $template = null): bool
    {
        return VoterInterface::ACCESS_GRANTED === $this->vote($role, PermissionVoter::ATTRIBUTE, new Permission($area, $action, template: $template));
    }

    #[Test]
    public function theAdministratorMayDoEverything(): void
    {
        self::assertTrue($this->allowed(Role::ADMIN, 'pages', 'delete'));
        self::assertTrue($this->allowed(Role::ADMIN, 'requests', 'edit'));
        self::assertTrue($this->allowed(Role::ADMIN, 'set:site', 'edit'));
        self::assertTrue($this->allowed(Role::ADMIN, 'col:tours', 'publish'));
    }

    #[Test]
    public function theEditorPublishesContentButOnlyReadsRequests(): void
    {
        self::assertTrue($this->allowed(Role::EDITOR, 'pages', 'publish'));
        self::assertTrue($this->allowed(Role::EDITOR, 'media', 'edit'));
        self::assertTrue($this->allowed(Role::EDITOR, 'requests', 'view'));
        self::assertFalse($this->allowed(Role::EDITOR, 'requests', 'edit'));
        self::assertFalse($this->allowed(Role::EDITOR, 'set:site', 'edit'));
    }

    #[Test]
    public function theAuthorWritesArticleDraftsWithoutPublishing(): void
    {
        self::assertTrue($this->allowed(Role::AUTHOR, 'pages', 'edit', 'blog_article'));
        self::assertFalse($this->allowed(Role::AUTHOR, 'pages', 'edit', 'product'));
        self::assertFalse($this->allowed(Role::AUTHOR, 'pages', 'publish', 'blog_article'));
        self::assertFalse($this->allowed(Role::AUTHOR, 'requests', 'view'));
    }

    #[Test]
    public function theRequestsRoleSeesNoPages(): void
    {
        self::assertTrue($this->allowed(Role::REQUESTS, 'requests', 'view'));
        self::assertFalse($this->allowed(Role::REQUESTS, 'pages', 'view'));
    }

    #[Test]
    public function aBlockedUserGetsNothing(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(Role::ADMIN, PermissionVoter::ATTRIBUTE, new Permission('pages', 'view'), User::BLOCKED));
    }

    #[Test]
    public function theAttributeFormSplitsOnTheLastColon(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(Role::ADMIN, 'itk:col:tours:edit'));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(Role::EDITOR, 'itk:requests:view'));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(Role::EDITOR, 'itk:requests:edit'));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(Role::ADMIN, 'ROLE_SOMETHING'));
    }

    #[Test]
    public function actionsImplyTheWeakerOnes(): void
    {
        self::assertSame(['view', 'edit', 'publish'], RoleResolver::closeActions(['publish']));
        self::assertSame(['view', 'edit', 'delete'], RoleResolver::closeActions(['delete']));
        self::assertSame(['view'], RoleResolver::closeActions(['view']));
    }

    #[Test]
    public function aRoleCannotExceedItsHolder(): void
    {
        $editor = ['perms' => ['pages' => ['view', 'edit']], 'templates' => null];
        $wider = ['perms' => ['pages' => ['view', 'edit', 'publish']], 'templates' => null];

        self::assertTrue(RoleResolver::covers($wider, $editor));
        self::assertFalse(RoleResolver::covers($editor, $wider));
    }
}
