<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\CriticalCss\CriticalCssGenerator;
use Itk\Cms\CriticalCss\Model\When;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Tests\App\QueryCounter;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ServabilityDatabaseTest extends KernelTestCase
{
    /** @var array<array-key, mixed> */
    private array $ids = [];
    /** @var array<array-key, mixed> */
    private array $paths = [];

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, itk: ['main_locale' => 'ru', 'locales' => ['ru' => ['name' => 'Russian'], 'kk' => ['name' => 'Kazakh'], 'en' => ['name' => 'English']]]);
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $failure = null;
        foreach ([
            fn () => $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\Redirect r WHERE r.fromPath IN (:paths) OR r.toPath IN (:paths)')->setParameter('paths', $this->paths ?: ['-'])->execute(),
            fn () => $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\Page p WHERE p.id IN (:ids)')->setParameter('ids', $this->ids ?: ['-'])->execute(),
            fn () => $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\Revision r WHERE r.kind = :kind AND r.resourceId IN (:ids)')->setParameter('kind', Revision::PAGE)->setParameter('ids', $this->ids ?: ['-'])->execute(),
        ] as $cleanup) {
            try {
                $cleanup();
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }
        try {
            if (null !== $failure) {
                throw $failure;
            }
        } finally {
            parent::tearDown();
        }
    }

    private function historical(string $locale, string $path): PageTranslation
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = new Page();
        $this->ids[] = $page->id;
        $this->paths[] = $path;
        $t = new PageTranslation($page, $locale);
        $t->draft = $t->published = ['url' => $path, 'title' => 'Historical'];
        $t->publishedUrl = $path;
        $t->status = PageTranslation::PUBLISHED;
        $page->translations->set($locale, $t);
        $em->persist($page);
        $em->flush();

        return $t;
    }

    #[Test]
    public function publishingAMigratedTranslationDoesNotStealAnotherLanguagesAddress(): void
    {
        $suffix = '/test-servability-' . \bin2hex(\random_bytes(8));
        $main = $this->historical('ru', $suffix);
        $secondary = $this->historical('en', $suffix);
        $pages = self::getContainer()->get(PageService::class);
        $this->paths[] = '/en' . $suffix;
        $pages->update($secondary->page->id, 'en', ['url' => '/en' . $suffix]);
        $pages->publish($secondary->page->id, 'en');
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM itk_redirect WHERE from_path = ?', [$suffix]));
        self::assertSame($suffix, $main->publishedUrl);
    }

    #[Test]
    public function movingAHistoricalMainVersionLeavesTheSecondaryRouteAvailable(): void
    {
        $suffix = '/test-servability-main-' . \bin2hex(\random_bytes(8));
        $secondary = $this->historical('kk', '/kk' . $suffix);
        $main = $this->historical('ru', '/kk' . $suffix);
        $pages = self::getContainer()->get(PageService::class);
        $this->paths[] = $suffix;
        $pages->update($main->page->id, 'ru', ['url' => $suffix]);
        $pages->publish($main->page->id, 'ru');
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM itk_redirect WHERE from_path = ?', ['/kk' . $suffix]));
        $response = self::$kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/kk' . $suffix));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/kk' . $suffix, $secondary->publishedUrl);
    }

    #[Test]
    public function movingAPrefixedVersionDoesNotRedirectItsFallbackToAnotherLivePage(): void
    {
        $suffix = 'test-servability-fallback-' . \bin2hex(\random_bytes(8));
        $fallback = $this->historical('en', '/en/' . $suffix);
        $moving = $this->historical('en', '/en//' . $suffix);
        $next = '/en/' . $suffix . '-next';
        $this->paths[] = $next;
        $pages = self::getContainer()->get(PageService::class);
        $pages->update($moving->page->id, 'en', ['url' => $next]);
        $pages->publish($moving->page->id, 'en');
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM itk_redirect WHERE from_path = ?', ['/en//' . $suffix]));
        $response = self::$kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/en//' . $suffix));
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/en/' . $suffix, $response->headers->get('Location'));
        $canonical = self::$kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/en/' . $suffix));
        self::assertSame(200, $canonical->getStatusCode());
        self::assertSame('/en/' . $suffix, $fallback->publishedUrl);
    }

    #[Test]
    public function movingAServedMainVersionStillRedirectsDespiteAnUnservedHistoricalTranslation(): void
    {
        $suffix = '/test-servability-served-' . \bin2hex(\random_bytes(8));
        $main = $this->historical('ru', $suffix);
        $this->historical('en', $suffix);
        $this->paths[] = $suffix . '-next';
        $pages = self::getContainer()->get(PageService::class);
        $pages->update($main->page->id, 'ru', ['url' => $suffix . '-next']);
        $pages->publish($main->page->id, 'ru');
        self::assertSame($suffix . '-next', self::getContainer()->get(Connection::class)->fetchOne('SELECT to_path FROM itk_redirect WHERE from_path = ?', [$suffix]));
    }

    #[Test]
    public function legacyCssHealthKeepsHistoricalLanguagesAndConfiguredHealthExcludesUnservedPaths(): void
    {
        $prefix = '/test-servability-health-' . \bin2hex(\random_bytes(8));
        $dir = __DIR__ . '/Fixture/test-servability-health-' . \bin2hex(\random_bytes(8));
        \mkdir($dir . '/node_modules/crittr', 0777, true);
        \file_put_contents($dir . '/node_modules/crittr/package.json', '{}');
        try {
            $c = self::getContainer();
            $critical = $c->get(\Itk\Cms\CriticalCss\CriticalCssService::class);
            $styles = new \Itk\Cms\CriticalCss\Stylesheets(true, [], [['width' => 375, 'height' => 800, 'mobile' => true]], [], [], 30, $dir);
            $configured = $c->get(\Itk\Cms\I18n\ContentLocales::class);
            $check = static fn ($locales) => (new \Itk\Cms\Health\CriticalCssHealthCheck($styles, $c->get(Connection::class), $critical, __DIR__ . '/../App/bin/fake-node.sh', $dir, $locales))->check();
            $before = $check($configured);
            $t = $this->historical('en', $prefix);
            $row = \Itk\Cms\Entity\CriticalCss::for($t);
            $row->markFailed($critical->wanted($t)->value, '', 'Historical unserved CSS failure', new \DateTimeImmutable());
            $em = $c->get(EntityManagerInterface::class);
            $em->persist($row);
            $em->flush();
            $after = $check($configured);
            self::assertSame($before->message, $after->message);
            $legacy = $check(new \Itk\Cms\I18n\ContentLocales('ru'));
            self::assertSame(\Itk\Cms\Health\HealthResult::WARN, $legacy->status);
            self::assertStringContainsString('Historical unserved CSS failure', $legacy->message);
        } finally {
            \unlink($dir . '/node_modules/crittr/package.json');
            \rmdir($dir . '/node_modules/crittr');
            \rmdir($dir . '/node_modules');
            \rmdir($dir);
        }
    }

    #[Test]
    public function criticalCssBatchLoadsOtherLanguagesInOneQuery(): void
    {
        $prefix = '/test-servability-batch-' . \bin2hex(\random_bytes(8));
        $ids = [];
        foreach (\range(1, 6) as $i) {
            $t = $this->historical('ru', $prefix . '-' . $i);
            $other = new PageTranslation($t->page, 'en');
            $other->draft = $other->published = ['url' => '/en' . $prefix . '-' . $i, 'title' => 'English'];
            $other->publishedUrl = '/en' . $prefix . '-' . $i;
            $other->status = PageTranslation::PUBLISHED;
            $t->page->translations->set('en', $other);
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->persist($other);
            $em->flush();
            $ids[$t->id] = When::Always;
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $queries = QueryCounter::during(static function () use ($ids): void {
            $report = self::getContainer()->get(CriticalCssGenerator::class)->generate($ids);
            self::assertSame([], $report->failed);
            self::assertSame(6, $report->generated);
        });
        $collections = \array_values(\array_filter($queries, static fn (string $sql): bool => \str_contains($sql, 'itk_page_translation') && (\preg_match('~page_id\s*=\s*\?~', $sql) || \str_contains($sql, 'LEFT JOIN itk_page_translation'))));
        self::assertCount(1, $collections, \implode("\n", $collections));
        $loaded = QueryCounter::during(function (): void {
            $c = self::getContainer();
            foreach ($this->ids as $id) {
                $page = $c->get(EntityManagerInterface::class)->find(Page::class, $id);
                self::assertSame(['ru', 'en'], \array_column($c->get(\Itk\Cms\Content\PageVersions::class)->live($page), 'code'));
            }
        });
        self::assertSame([], $loaded, 'The batch leaves complete language collections ready for every page.');
    }
}
