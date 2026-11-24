<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\DraftVersion;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\User;
use Itk\Cms\Repository\RevisionRepository;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Support\Json;
use Itk\Cms\Tests\Core\Fixture\GuardSection;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Версия черновика не должна быть оракулом для закрытого поля: человек без права на поле (canSee) видит `version`, и если это несолёный хеш всего черновика,
 * значение поля с малой энтропией подбирается перебором. Версия считается ключевым хешем (kernel.secret).
 */
final class DraftVersionSecretTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';
    /** @var list<string> */
    private array $pageIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $registry = self::getContainer()->get(ResourceRegistry::class);
        $registry->add(new GuardSection());
        $registry->add(new TripsCollection());

        // a role that may read and edit the test collection and pages, but has no «set:users»: the field `secret` is closed to it
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $role = new Role('test-guard', 'Test узкая');
        $role->perms = ['col:test_trips' => ['view', 'edit'], 'pages' => ['view', 'edit']];
        $em->persist($role);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :c')->setParameter('c', 'test_trips')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Page p WHERE p.id IN (:ids)')->setParameter('ids', [] === $this->pageIds ? ['-'] : $this->pageIds)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey LIKE :k OR r.resourceId IN (:ids)')
            ->setParameter('k', 'test%')->setParameter('ids', [] === $this->pageIds ? ['-'] : $this->pageIds)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Role r WHERE r.key = :k')->setParameter('k', 'test-guard')->execute();
        parent::tearDown();
    }

    private function signIn(string $role): void
    {
        /** @var User $user */
        $user = self::getContainer()->get(UserManager::class)->create('test-' . $role . '@example.com', 'Тест ' . $role, $role, 'password-for-test');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
    }

    /** @return array<string, mixed> */
    private function api(string $method, string $path): array
    {
        $this->client->request($method, '/admin/api/' . $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * What the version used to be: a bare sha1 of the state, which anyone who sees the other fields could try with a guessed value.
     *
     * @param array<array-key, mixed> $state
     */
    private function unkeyed(array $state): string
    {
        return \substr(\sha1(Json::encode(Json::canonical($state))), 0, 16);
    }

    #[Test]
    public function theVersionOfARecordCannotConfirmAGuessOfAClosedField(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        $made = self::getContainer()->get(CollectionService::class)->create('test_trips', ['title' => 'Test Оракул', 'seats' => 3, 'secret' => '4711']);

        $this->signIn('test-guard');
        $item = $this->api('GET', 'collections/test_trips/' . $made['id'])['item'];
        self::assertArrayNotHasKey('secret', $item, 'canSee: the value is not handed out');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(CollectionItem::class, $made['id']);
        $state = ['draft' => $stored->translations['ru']['draft'], 'parentId' => $stored->parentId];
        self::assertSame('4711', $state['draft']['secret'], 'the control: the closed value is in the draft');
        self::assertNotSame($this->unkeyed($state), $item['version'], 'the version is not a bare hash of the draft: the value of the closed field could be tried against it');
        $secret = (string) self::getContainer()->getParameter('kernel.secret');
        self::assertSame(\substr(\hash_hmac('sha256', Json::encode(Json::canonical($state)), $secret), 0, 16), $item['version'], 'keyed with the secret of the site, not with a constant');
    }

    #[Test]
    public function theVersionOfAPageCannotConfirmAGuessOfAClosedField(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(null);
        $page = self::getContainer()->get(PageService::class)->create([
            'template' => 'default', 'title' => 'Test оракул', 'url' => '/test-oracle',
            'sections' => [['id' => 's-one', 'type' => 'test_guard_sec', 'data' => ['shown' => 'a', 'secret' => '4711', 'locked' => 'c']]],
        ]);
        $this->pageIds[] = $page['id'];

        $this->signIn('test-guard');
        $got = $this->api('GET', 'pages/' . $page['id']);
        self::assertArrayNotHasKey('secret', (array) $got['sections'][0]['data'], 'canSee: the value is not handed out');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $p = $em->find(Page::class, $page['id']);
        $state = ['draft' => $p->translation('ru')->draft, 'parentId' => $p->parentId(), 'template' => $p->template];
        self::assertNotSame($this->unkeyed($state), $got['version']);
        self::assertSame($got['version'], $this->api('GET', 'pages/' . $page['id'] . '/draft')['page']['version']);
    }

    #[Test]
    public function theVersionDependsOnTheSecretOfTheSiteAndOnTheStateAlone(): void
    {
        $revisions = self::getContainer()->get(RevisionRepository::class);
        $state = ['draft' => ['title' => 'A', 'seats' => 3], 'parentId' => null];

        $one = new DraftVersion($revisions, 'secret-one');
        $other = new DraftVersion($revisions, 'secret-two');
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $one->of($state));
        self::assertSame($one->of($state), $one->of(['parentId' => null, 'draft' => ['seats' => 3, 'title' => 'A']]), 'the order of keys does not matter');
        self::assertNotSame($one->of($state), $one->of(['draft' => ['title' => 'A', 'seats' => 4], 'parentId' => null]));
        self::assertNotSame($one->of($state), $other->of($state), 'a site with another secret has other versions');
    }
}
