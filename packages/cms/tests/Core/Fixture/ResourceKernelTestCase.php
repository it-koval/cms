<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\User;
use Itk\Cms\Resource\ResourceRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Ядро с тестовыми классами-ресурсами (test_trips, test_tpl), вошедший администратор; за собой всё удаляет. */
abstract class ResourceKernelTestCase extends KernelTestCase
{
    protected CollectionService $collections;
    protected PageService $pages;
    /** @var list<string> */
    protected array $pageIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->prepare();
    }

    protected function prepare(): void
    {
        $registry = self::getContainer()->get(ResourceRegistry::class);
        $registry->add(new TripsCollection());
        $registry->add(new TestTemplate());
        $registry->add(new TestSettings());
        $this->collections = self::getContainer()->get(CollectionService::class);
        $this->pages = self::getContainer()->get(PageService::class);
        TripsCollection::$log = [];
        TestTemplate::$log = [];
        $this->signIn('admin');
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    protected function clean(): void
    {
        $this->signIn('admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :c')->setParameter('c', 'test_trips')->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key LIKE :k')->setParameter('k', 'test%')->execute();
        foreach (\array_reverse($this->pageIds) as $id) {
            try {
                $this->pages->remove($id);
            } catch (\Throwable) {
            }
        }
        // the history of what the test touched goes too
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceKey LIKE :k OR r.resourceId IN (:ids)')
            ->setParameter('k', 'test%')->setParameter('ids', [] === $this->pageIds ? ['-'] : $this->pageIds)->execute();
        $this->pageIds = [];
        self::getContainer()->get('security.token_storage')->setToken(null);
    }

    protected function signIn(string $role): void
    {
        $user = new User($role . '@example.com', $role, $role);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'itk_admin', []));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function trip(string $title, array $data = []): array
    {
        return $this->collections->create('test_trips', ['title' => $title] + $data);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function page(string $template, string $title, string $url, ?string $parentId = null, array $fields = []): array
    {
        $page = $this->pages->create(['template' => $template, 'title' => $title, 'url' => $url, 'parentId' => $parentId, 'fields' => $fields]);
        $this->pageIds[] = $page['id'];

        return $page;
    }
}
