<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\Listing;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Controller\Admin\DashboardController;
use Itk\Cms\Entity\CollectionItem;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\User;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Tests\App\QueryCounter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Reproducible bounded profiles for conditional 0.3.0 work; timing is reported, never a CI threshold. */
final class ConditionalPerformanceProfileTest extends KernelTestCase
{
    #[Test]
    public function dashboardAndPublicListsReportTheirActualHydrationCost(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $c->get('security.token_storage')->setToken(new UsernamePasswordToken(new User('profile@example.test', 'Profile', 'admin'), 'itk_admin'));
        $pageIds = $itemIds = [];
        $prefix = '/test-profile-' . \bin2hex(\random_bytes(6));
        try {
            $blog = new Page('blog');
            $em->persist($blog);
            $pageIds[] = $blog->id;
            for ($i = 0; $i < 200; ++$i) {
                $page = new Page($i < 20 ? 'blog_article' : 'home', $i < 20 ? $blog : null);
                $page->position = $i;
                $pageIds[] = $page->id;
                $t = new PageTranslation($page, 'ru');
                $t->published = ['title' => 'Profile ' . $i, 'url' => $prefix . '/' . $i, 'sections' => []];
                for ($j = 0; $j < 20; ++$j) {
                    $t->published['sections'][] = ['id' => 's' . $j, 'type' => 'hero', 'data' => ['headline' => 'Live<br>headline']];
                }
                $t->publishedUrl = $t->published['url'];
                $t->status = PageTranslation::PUBLISHED;
                $t->publishedAt = new \DateTimeImmutable();
                $t->draft = $t->published;
                $t->draft['title'] = 'Draft profile ' . $i;
                $page->translations->set('ru', $t);
                $em->persist($page);
                $item = new CollectionItem('tours');
                $itemIds[] = $item->id;
                $item->translations = ['ru' => ['draft' => ['title' => 'Tour ' . $i], 'published' => $i < 20 ? ['title' => 'Tour ' . $i] : null, 'status' => $i < 20 ? 'published' : 'draft', 'updatedAt' => '2026-11-24T00:00:00Z']];
                $em->persist($item);
            }
            $em->flush();
            $em->clear();
            $start = \hrtime(true);
            $answer = [];
            $queries = QueryCounter::during(static function () use ($c, &$answer): void {
                $answer = \json_decode($c->get(DashboardController::class)()->getContent(), true);
            });
            $ms = (\hrtime(true) - $start) / 1e6;
            $map = $em->getUnitOfWork()->getIdentityMap();
            $hydratedPages = \count($map[Page::class] ?? []);
            $hydratedItems = \count($map[CollectionItem::class] ?? []);
            self::assertGreaterThanOrEqual(200, $answer['locales']['ru']['pages']['count']);
            self::assertGreaterThanOrEqual(200, $answer['locales']['ru']['collections']['count']);
            self::assertSame(0, $hydratedPages + $hydratedItems + \count($map[PageTranslation::class] ?? []));
            self::assertGreaterThanOrEqual(380, \count($answer['unpublished']));
            \fwrite(\STDERR, \sprintf("\n5.1 dashboard 200 pages x20 sections +200 items: %.1f ms, %d SQL, %d pages/%d items hydrated; unpublished rows %d; JSON %.1f KiB\n", $ms, \count($queries), $hydratedPages, $hydratedItems, \count($answer['unpublished']), \strlen(\json_encode($answer)) / 1024));

            $em->clear();
            $start = \hrtime(true);
            $listed = [];
            $queries = QueryCounter::during(static function () use ($c, $blog, &$listed): void {
                $listed = $c->get(Listing::class)->forPage($blog->id);
            });
            $map = $em->getUnitOfWork()->getIdentityMap();
            self::assertCount(20, $listed);
            self::assertLessThanOrEqual(21, \count($map[Page::class] ?? []));
            self::assertLessThanOrEqual(20, \count($map[PageTranslation::class] ?? []));
            \fwrite(\STDERR, \sprintf("5.4 page listing: %.1f ms, %d SQL, %d pages/%d translations hydrated for20 results\n", (\hrtime(true) - $start) / 1e6, \count($queries), \count($map[Page::class] ?? []), \count($map[PageTranslation::class] ?? [])));

            $em->clear();
            $start = \hrtime(true);
            $listed = [];
            $queries = QueryCounter::during(static function () use ($c, &$listed): void {
                $listed = $c->get(CollectionService::class)->published('tours');
            });
            self::assertCount(20, \array_filter($listed, static fn (array $item): bool => \in_array($item['id'], $itemIds, true)));
            self::assertCount(0, $em->getUnitOfWork()->getIdentityMap()[CollectionItem::class] ?? []);
            \fwrite(\STDERR, \sprintf("5.4 collection listing: %.1f ms, %d SQL, %d items hydrated for20 live fixture results\n", (\hrtime(true) - $start) / 1e6, \count($queries), \count($em->getUnitOfWork()->getIdentityMap()[CollectionItem::class] ?? [])));
        } finally {
            $em->clear();
            foreach (\array_reverse($pageIds) as $id) {
                $em->getConnection()->executeStatement('DELETE FROM itk_page WHERE id = ?', [$id]);
            }
            foreach ($itemIds as $id) {
                $em->getConnection()->executeStatement('DELETE FROM itk_collection_item WHERE id = ?', [$id]);
            }
        }
    }

    #[Test]
    public function analyticsAndContentQueriesBothBypassTheCacheWithoutAnHtmlIndependenceContract(): void
    {
        self::bootKernel();
        $trusted = Request::getTrustedHosts();
        Request::setTrustedHosts(['^profile\\.example\\.test$']);
        try {
            $page = new PageTranslation(new Page(), 'ru');
            $page->published = ['url' => '/profile'];
            $cache = new PageCache(new ArrayAdapter(), true, false, 60, new MockClock(), self::getContainer()->get(SitemapBuilder::class));
            $out = [];
            foreach (['', '?utm_source=profile', '?category=one'] as $query) {
                $out[$query] = $cache->bypasses(Request::create('https://profile.example.test/profile' . $query), $page);
            }
            self::assertSame(['' => false, '?utm_source=profile' => true, '?category=one' => true], $out);
            \fwrite(\STDERR, '5.4 same URL cache bypass: ' . \json_encode($out) . "\n");
        } finally {
            Request::setTrustedHosts($trusted);
        }
    }
}
