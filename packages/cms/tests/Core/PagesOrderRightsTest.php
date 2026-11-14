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

/** PUT pages/order: порядок страниц меняет только тот, у кого есть право на шаблон каждой страницы, которая сдвигается. */
final class PagesOrderRightsTest extends WebTestCase
{
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

        $role = new Role('test-default-only', 'Test только default');
        $role->perms = ['pages' => ['view', 'edit']];
        $role->templates = ['default'];
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($role);
        $em->flush();

        // pages are made by an administrator
        $admin = $c->get(UserManager::class)->create('test-order-admin@example.com', 'Админ', 'admin', 'password-for-test');
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'itk_admin', []));
    }

    protected function tearDown(): void
    {
        $c = self::getContainer();
        $c->get('security.token_storage')->setToken(null);
        $em = $c->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['email' => 'test-order-admin@example.com']);
        if (null !== $admin) {
            $c->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'itk_admin', []));
        }
        foreach (\array_reverse($this->ids) as $id) {
            try {
                $this->pages->remove($id);
            } catch (\Throwable) {
            }
        }
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId IN (:ids)')->setParameter('ids', [] === $this->ids ? ['-'] : $this->ids)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key = :k')->setParameter('k', 'test-default-only')->execute();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function page(string $template, string $title, string $url, ?string $parentId = null): array
    {
        $page = $this->pages->create(['template' => $template, 'title' => $title, 'url' => $url, 'parentId' => $parentId]);
        $this->ids[] = $page['id'];

        return $page;
    }

    private function signIn(string $email, string $role): void
    {
        $user = self::getContainer()->get(UserManager::class)->create($email, 'Порядок', $role, 'password-for-test');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
    }

    /** @param list<string> $ids */
    private function order(array $ids, int $status): void
    {
        $this->client->request('PUT', '/admin/api/pages/order', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf], \json_encode(['ids' => $ids]));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param list<string> $ids
     *
     * @return list<int>
     */
    private function positions(array $ids): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return \array_map(fn (string $id): int => $this->pages->find($id)->position, $ids);
    }

    #[Test]
    public function aRoleLimitedToOneTemplateCannotReorderPagesOfAnother(): void
    {
        $parent = $this->page('default', 'Test родитель', '/test-order-parent');
        $a = $this->page('test_tpl', 'Test A', '/test-order-parent/a', $parent['id']);
        $b = $this->page('test_tpl', 'Test B', '/test-order-parent/b', $parent['id']);
        $before = $this->positions([$a['id'], $b['id']]);
        $this->signIn('test-order-limited@example.com', 'test-default-only');

        $this->order([$b['id'], $a['id']], 403);

        self::assertSame($before, $this->positions([$a['id'], $b['id']]), 'nothing moved');
    }

    #[Test]
    public function aMixedListIsRefusedAsAWholeBeforeAnythingIsWritten(): void
    {
        $x = $this->page('default', 'Test X', '/test-order-x');
        $y = $this->page('default', 'Test Y', '/test-order-y');
        $parent = $this->page('default', 'Test родитель 2', '/test-order-parent2');
        $z = $this->page('test_tpl', 'Test Z', '/test-order-parent2/z', $parent['id']);
        $all = [$x['id'], $y['id'], $z['id']]; // Z has position 0 and is put third: it moves, and its template is not allowed
        $before = $this->positions($all);
        $this->signIn('test-order-limited2@example.com', 'test-default-only');

        $this->client->request('GET', '/admin/api/pages');
        self::assertResponseIsSuccessful();
        $visible = \array_column(\json_decode((string) $this->client->getResponse()->getContent(), true), 'id');
        self::assertContains($x['id'], $visible);
        self::assertNotContains($z['id'], $visible);
        $this->order($all, 403);

        self::assertSame($before, $this->positions($all));
    }

    #[Test]
    public function aRoleMayReorderPagesOfItsOwnTemplate(): void
    {
        $x = $this->page('default', 'Test X1', '/test-order-x1');
        $y = $this->page('default', 'Test Y1', '/test-order-y1');
        $this->signIn('test-order-limited3@example.com', 'test-default-only');

        $this->order([$y['id'], $x['id']], 200);

        [$px, $py] = $this->positions([$x['id'], $y['id']]);
        self::assertLessThan($px, $py, 'Y is now before X');
    }

    #[Test]
    public function anAdministratorMayReorderAnything(): void
    {
        $parent = $this->page('default', 'Test родитель 3', '/test-order-parent3');
        $a = $this->page('test_tpl', 'Test A3', '/test-order-parent3/a', $parent['id']);
        $b = $this->page('test_tpl', 'Test B3', '/test-order-parent3/b', $parent['id']);
        $this->signIn('test-order-admin2@example.com', 'admin');

        $this->order([$b['id'], $a['id']], 200);

        self::assertSame([1, 0], $this->positions([$a['id'], $b['id']]));
    }

    #[Test]
    public function aPageThatKeepsItsPlaceIsNotChecked(): void
    {
        $parent = $this->page('default', 'Test родитель 4', '/test-order-parent4');
        $a = $this->page('test_tpl', 'Test A4', '/test-order-parent4/a', $parent['id']);
        $this->signIn('test-order-limited4@example.com', 'test-default-only');

        // a single page in its own place: nobody moves, so there is nothing to forbid
        $this->order([$a['id']], 200);
    }
}
