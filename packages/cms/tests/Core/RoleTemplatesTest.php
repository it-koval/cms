<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\Core\Fixture\TestTemplate;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** PATCH|POST roles: тот, кто правит роли, не даёт роли больше шаблонов страниц, чем есть у него самого. */
final class RoleTemplatesTest extends WebTestCase
{
    private const LIMITED = 'test-rt-limited';

    private KernelBrowser $client;
    private string $csrf = '';
    private PageService $pages;
    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $c = self::getContainer();
        $c->get(ResourceRegistry::class)->add(new TestTemplate());
        $this->pages = $c->get(PageService::class);

        $em = $c->get(EntityManagerInterface::class);
        $this->role(self::LIMITED, ['set:users' => ['view', 'edit'], 'pages' => ['view', 'edit']], ['default']);
        $this->role('test-rt-wide', ['pages' => ['view', 'edit']], null);
        $this->role('test-rt-people', ['set:users' => ['view', 'edit']], null);
        $this->role('test-rt-viewer', ['pages' => ['view']], ['test_tpl']);
        $this->role('test-rt-own', ['pages' => ['view']], ['default']);
        $this->role('test-rt-plain', ['set:users' => ['view']], null);
        $this->role('test-rt-strong', ['pages' => ['view', 'edit', 'publish', 'delete']], ['test_tpl']);
        $this->role('test-rt-stale', ['pages' => ['view']], ['test_gone_tpl']);
        $this->role('test-rt-two', ['pages' => ['view', 'edit']], ['default', 'test_tpl']);
        $em->flush();

        $admin = $c->get(UserManager::class)->create('test-rt-admin@example.com', 'Админ', 'admin', 'password-for-test');
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'itk_admin', []));
    }

    protected function tearDown(): void
    {
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'test-rt-admin@example.com']);
        if (null !== $admin) {
            $c->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'itk_admin', []));
        }
        foreach (\array_reverse($this->ids) as $id) {
            try {
                $this->pages->remove($id);
            } catch (\Throwable) {
            }
        }
        $c->get('security.token_storage')->setToken(null);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', [] === $this->ids ? ['-'] : $this->ids)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key LIKE :k OR r.name LIKE :n')->setParameter('k', 'test-rt-%')->setParameter('n', 'Test копия%')->execute();
        parent::tearDown();
    }

    /**
     * @param array<string, list<string>> $perms
     * @param list<string>|null $templates
     */
    private function role(string $key, array $perms, ?array $templates): void
    {
        $role = new Role($key, 'Test ' . $key);
        $role->perms = $perms;
        $role->templates = $templates;
        self::getContainer()->get(EntityManagerInterface::class)->persist($role);
    }

    private function signIn(string $role): void
    {
        $user = self::getContainer()->get(UserManager::class)->create('test-' . $role . '@example.com', 'Тест ' . $role, $role, 'password-for-test');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
    }

    /**
     * @param array<int|string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function otherPage(): string
    {
        $parent = $this->pages->create(['template' => 'default', 'title' => 'Test родитель', 'url' => '/test-rt-parent']);
        $this->ids[] = $parent['id'];
        $page = $this->pages->create(['template' => 'test_tpl', 'title' => 'Test чужой шаблон', 'url' => '/test-rt-parent/other', 'parentId' => $parent['id']]);
        $this->ids[] = $page['id'];

        return $page['id'];
    }

    #[Test]
    public function aLimitedManagerCannotMakeHisRoleCoverEveryTemplate(): void
    {
        $page = $this->otherPage();
        $this->signIn(self::LIMITED);
        $this->api('GET', 'pages/' . $page . '/draft', [], 403);

        $role = $this->api('PATCH', 'roles/' . self::LIMITED, ['templates' => null]);

        self::assertSame(['default'], $role['templates'], 'null is not accepted: the role stays within the templates of the person');
        $this->api('GET', 'pages/' . $page . '/draft', [], 403);
    }

    #[Test]
    public function aManagerWithoutPagesCannotGiveARoleEveryTemplate(): void
    {
        $this->signIn('test-rt-people');

        $role = $this->api('PATCH', 'roles/test-rt-two', ['templates' => null]);

        self::assertSame(['default', 'test_tpl'], $role['templates'], 'a person without pages holds no template: the role keeps only what it had');
    }

    #[Test]
    public function pagesAddedToARoleWithoutThemComeWithTheTemplatesOfTheManagerOnly(): void
    {
        $page = $this->otherPage();
        $this->signIn(self::LIMITED);
        $perms = ['set:users' => ['view', 'edit'], 'pages' => ['view', 'edit']];

        $this->api('PATCH', 'roles/test-rt-people', ['perms' => $perms, 'templates' => ['test_tpl']], 422);
        $role = $this->api('PATCH', 'roles/test-rt-people', ['perms' => $perms]);

        self::assertSame(['default'], $role['templates'], 'templates null of a role without pages is not «every template»');
        $this->client->loginUser(self::getContainer()->get(UserManager::class)->create('test-rt-people-holder@example.com', 'Тест', 'test-rt-people', 'password-for-test'), 'itk_admin');
        $this->api('GET', 'pages/' . $page . '/draft', [], 403);
    }

    #[Test]
    public function aLimitedManagerGivesAPagesActionOnlyOnTemplatesHeReaches(): void
    {
        $this->signIn(self::LIMITED);

        $other = $this->api('PATCH', 'roles/test-rt-viewer', ['perms' => ['pages' => ['view', 'edit']], 'templates' => ['test_tpl']]);
        $own = $this->api('PATCH', 'roles/test-rt-own', ['perms' => ['pages' => ['view', 'edit']], 'templates' => ['default']]);

        self::assertSame(['view'], $other['perms']['pages'], 'editing on a template the manager cannot reach is not his to give');
        self::assertSame(['view', 'edit'], $own['perms']['pages']);
    }

    #[Test]
    public function aCopyWithNoTemplateOfTheAuthorHasNoPagesAndCanBeSavedAgain(): void
    {
        $this->signIn(self::LIMITED);

        $copy = $this->api('POST', 'roles', ['name' => 'Test копия чужая', 'copyOf' => 'test-rt-viewer'], 201);
        self::assertArrayNotHasKey('pages', $copy['perms']);

        $this->api('PATCH', 'roles/' . $copy['id'], ['name' => 'Test копия чужая 2', 'perms' => $copy['perms'], 'templates' => $copy['templates']]);
    }

    #[Test]
    public function aManagerWithoutPagesStillSavesARoleWithoutPages(): void
    {
        $this->signIn('test-rt-people');

        $role = $this->api('PATCH', 'roles/test-rt-plain', ['name' => 'Test plain 2', 'perms' => ['set:users' => ['view']], 'templates' => null]);

        self::assertSame('Test plain 2', $role['name']);
    }

    #[Test]
    public function aManagerAddsHisTemplateOnlyToARoleWhoseActionsHeHolds(): void
    {
        $this->signIn(self::LIMITED);

        $role = $this->api('PATCH', 'roles/test-rt-strong', ['perms' => ['pages' => ['view', 'edit', 'publish', 'delete']], 'templates' => ['test_tpl', 'default']]);

        self::assertSame(['test_tpl'], $role['templates'], 'publishing and deleting «default» pages is not his to give');
        self::assertSame(['view', 'edit', 'publish', 'delete'], $role['perms']['pages'], 'what the role held stays');
    }

    #[Test]
    public function aManagerWithoutPagesTakesPagesAwayFromARoleWhoseTemplatesAreGone(): void
    {
        $this->signIn('test-rt-people');

        $role = $this->api('PATCH', 'roles/test-rt-stale', ['perms' => [], 'templates' => null]);

        self::assertArrayNotHasKey('pages', $role['perms']);
    }

    #[Test]
    public function aLimitedManagerCannotAddATemplateHeDoesNotHold(): void
    {
        $page = $this->otherPage();
        $this->signIn(self::LIMITED);

        $role = $this->api('PATCH', 'roles/' . self::LIMITED, ['templates' => ['default', 'test_tpl']]);

        self::assertSame(['default'], $role['templates']);
        $this->api('GET', 'pages/' . $page . '/draft', [], 403);
    }

    #[Test]
    public function aLimitedManagerStillGetsTheValidationErrorForAListOfNothingHeMayGive(): void
    {
        $this->signIn(self::LIMITED);

        $this->api('PATCH', 'roles/' . self::LIMITED, ['templates' => []], 422);
        $this->api('PATCH', 'roles/' . self::LIMITED, ['templates' => ['test_tpl']], 422);
    }

    #[Test]
    public function aLimitedManagerMayNarrowARoleAndKeepWhatItAlreadyHolds(): void
    {
        $this->signIn(self::LIMITED);

        // the role «two» holds test_tpl already: it may stay, but not be widened to others
        $kept = $this->api('PATCH', 'roles/test-rt-two', ['templates' => ['test_tpl', 'default', 'blog']]);
        self::assertEqualsCanonicalizing(['default', 'test_tpl'], $kept['templates']);

        $narrowed = $this->api('PATCH', 'roles/test-rt-two', ['templates' => ['default']]);
        self::assertSame(['default'], $narrowed['templates']);

        // a role that already covers every template can stay so
        self::assertNull($this->api('PATCH', 'roles/test-rt-wide', ['templates' => null])['templates']);
    }

    #[Test]
    public function anAdministratorMayStillGiveARoleEveryTemplate(): void
    {
        $this->signIn('admin');

        self::assertNull($this->api('PATCH', 'roles/test-rt-two', ['templates' => null])['templates']);
        self::assertSame(['default', 'test_tpl'], $this->api('PATCH', 'roles/test-rt-two', ['templates' => ['default', 'test_tpl']])['templates']);
    }

    #[Test]
    public function aCopyOfAWiderRoleIsNarrowedToTheTemplatesOfThePersonWhoMakesIt(): void
    {
        $this->signIn(self::LIMITED);

        $fromAll = $this->api('POST', 'roles', ['name' => 'Test копия всех', 'copyOf' => 'test-rt-wide'], 201);
        self::assertSame(['default'], $fromAll['templates'], 'a role for every template is cut to the templates of the author');

        $fromTwo = $this->api('POST', 'roles', ['name' => 'Test копия двух', 'copyOf' => 'test-rt-two'], 201);
        self::assertSame(['default'], $fromTwo['templates']);
    }

    #[Test]
    public function anAdministratorCopiesARoleAsItIs(): void
    {
        $this->signIn('admin');

        self::assertNull($this->api('POST', 'roles', ['name' => 'Test копия всех админом', 'copyOf' => 'test-rt-wide'], 201)['templates']);
        self::assertSame(['default', 'test_tpl'], $this->api('POST', 'roles', ['name' => 'Test копия двух админом', 'copyOf' => 'test-rt-two'], 201)['templates']);
    }
}
