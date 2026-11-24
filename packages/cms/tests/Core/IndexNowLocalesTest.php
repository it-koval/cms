<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\EditLock;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Revision;
use Itk\Cms\IndexNow\Model\PingIndexNow;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\TemporarySettings;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Full live paths are announced after multilingual page mutations, once per operation. */
final class IndexNowLocalesTest extends KernelTestCase
{
    use TemporarySettings;

    private PageService $pages;
    /** @var list<string> */
    private array $ids = [];
    /** @var list<string> */
    private array $paths = [];
    /** @var list<string> */
    private array $ruleIds = [];
    private string $pathPrefix;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, framework: ['http_client' => ['enabled' => true]], itk: [
            'main_locale' => 'ru',
            'locales' => ['ru' => ['name' => 'Русский'], 'kk' => ['name' => 'Қазақша'], 'tr' => ['name' => 'Türkçe'], 'zh' => ['name' => '中文'], 'en' => ['name' => 'English']], // i18n-ignore: configured language names
        ]);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->pages = $c->get(PageService::class);
        $this->pathPrefix = 'test-indexnow-locales-' . \bin2hex(\random_bytes(8));
        $this->setting('domain', 'locales.example.org');
        $this->setting('indexNow', true);
        $this->transport()->reset();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $failure = null;
        // Attempt every cleanup even when an assertion or one of the cleanup steps failed.
        foreach ([
            fn () => $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\Redirect r WHERE r.fromPath IN (:paths) OR r.toPath IN (:paths)')->setParameter('paths', $this->paths ?: ['-'])->execute(),
            fn () => $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\Page p WHERE p.id IN (:ids)')->setParameter('ids', $this->ids ?: ['-'])->execute(),
            fn () => $em->createQuery('DELETE FROM Itk\\Cms\\Entity\\Revision r WHERE r.kind = :kind AND r.resourceId IN (:ids)')->setParameter('kind', Revision::PAGE)->setParameter('ids', $this->ids ?: ['-'])->execute(),
            function (): void {
                $rules = self::getContainer()->get(SeoRuleService::class);
                foreach ($this->ruleIds as $id) {
                    $rules->remove($id);
                }
            },
            fn () => $this->restoreSettings(),
            fn () => $this->transport()->reset(),
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

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    /** @return list<string> */
    private function queued(): array
    {
        $paths = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof PingIndexNow) {
                $paths[] = $message->path;
            }
        }
        $this->transport()->reset();

        return $paths;
    }

    private function draft(): string
    {
        $page = $this->pages->create(['template' => 'default', 'title' => 'IndexNow locales', 'url' => $this->path('ru', 'draft'), 'locale' => 'ru']);
        $this->ids[] = $page['id'];

        return $page['id'];
    }

    private function path(string $locale, string $suffix): string
    {
        $path = ('ru' === $locale ? '' : '/' . $locale) . '/' . $this->pathPrefix . '-' . $suffix;
        $this->paths[] = $path;

        return $path;
    }

    private function noindex(string $locale, string $path): void
    {
        $rule = self::getContainer()->get(SeoRuleService::class)->create(['path' => $path, 'locale' => $locale, 'robots' => 'noindex,follow']);
        $this->ruleIds[] = $rule->id;
    }

    private function translation(string $id, string $locale, string $path): void
    {
        $this->pages->addTranslation($id, $locale, 'ru');
        $this->pages->update($id, $locale, ['url' => $path]);
        $this->pages->publish($id, $locale);
    }

    #[Test]
    public function publishingAndMovingKazakhQueuesFullPathsWithoutDuplicates(): void
    {
        $id = $this->draft();
        $old = $this->path('kk', 'about');
        $new = $this->path('kk', 'about-us');
        $this->translation($id, 'kk', $old);
        self::assertSame([$old], $this->queued());

        $this->pages->publish($id, 'kk');
        self::assertSame([$old], $this->queued(), 'unchanged live paths are announced once');

        $this->pages->update($id, 'kk', ['url' => $new]);
        self::assertSame([], $this->queued(), 'a draft address does not change the live site');
        $this->pages->publish($id, 'kk');
        self::assertSame([$old, $new], $this->queued());
    }

    #[Test]
    public function unpublishAndTranslationRemovalAnnounceTheLastLivePath(): void
    {
        $id = $this->draft();
        $live = $this->path('kk', 'about');
        $this->translation($id, 'kk', $live);
        $this->queued();
        $this->pages->update($id, 'kk', ['url' => $this->path('kk', 'unpublished-draft')]);

        $this->pages->unpublish($id, 'kk');
        $this->assertQueued($live);
        $this->pages->republish($id, 'kk');
        $this->assertQueued($live);
        $this->pages->removeTranslation($id, 'kk');
        $this->assertQueued($live);
        self::assertNotNull($this->pages->find($id)->translation('ru'), 'translation removal retains the shared page');
    }

    private function assertQueued(string $path): void
    {
        self::assertSame([$path], $this->queued());
    }

    #[Test]
    public function deletingAPageAnnouncesEveryLiveIndexableVersionAndNoDraftPath(): void
    {
        $id = $this->draft();
        $expected = [(string) $this->pages->find($id)->translation('ru')->draft['url']];
        $this->pages->publish($id, 'ru');
        foreach (['kk', 'tr', 'zh', 'en'] as $locale) {
            $path = $this->path($locale, 'about');
            if ('en' === $locale) {
                $this->noindex($locale, $path);
            } else {
                $expected[] = $path;
            }
            $this->translation($id, $locale, $path);
        }
        // A historical translation can remain in storage after its language was disabled.
        $page = $this->pages->find($id);
        $disabled = new PageTranslation($page, 'de');
        $disabledPath = $this->path('de', 'about');
        $disabled->draft = $disabled->published = ['title' => 'Disabled', 'url' => $disabledPath, 'seo' => []];
        $disabled->status = PageTranslation::PUBLISHED;
        $disabled->publishedUrl = $disabledPath;
        $page->translations->set('de', $disabled);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($disabled);
        $em->flush();
        $this->pages->update($id, 'kk', ['url' => $this->path('kk', 'draft-about')]);
        $this->queued();

        $this->pages->remove($id);

        $paths = $this->queued();
        self::assertEqualsCanonicalizing($expected, $paths);
        self::assertCount(4, $paths, 'one notification per live path');
    }

    #[Test]
    public function rolledBackTranslationRemovalQueuesNothing(): void
    {
        $id = $this->draft();
        $this->translation($id, 'kk', $this->path('kk', 'about'));
        $this->queued();
        try {
            self::getContainer()->get(EditLock::class)->transactional(function () use ($id): void {
                $this->pages->removeTranslation($id, 'kk');
                self::assertSame([], $this->queued(), 'notifications wait until commit');
                throw new \RuntimeException('Rollback the translation removal');
            });
            self::fail('The transaction must roll back');
        } catch (\RuntimeException $e) {
            self::assertSame('Rollback the translation removal', $e->getMessage());
        }

        self::assertSame([], $this->queued(), 'a rolled-back deletion must not announce a removed path');
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNotNull($this->pages->getPublished($id, 'kk'));
    }

    #[Test]
    public function previouslyNoindexPathsAreNotAnnouncedWhenMovedOrRemoved(): void
    {
        $id = $this->draft();
        $hidden = $this->path('kk', 'hidden');
        $visible = $this->path('kk', 'visible');
        $this->noindex('kk', $hidden);
        $this->translation($id, 'kk', $hidden);
        self::assertSame([], $this->queued());
        $this->pages->update($id, 'kk', ['url' => $visible]);
        $this->pages->publish($id, 'kk');
        self::assertSame([$visible], $this->queued(), 'the old noindex path is excluded by its own address rule');

        $hiddenEnglish = $this->path('en', 'hidden');
        $this->noindex('en', $hiddenEnglish);
        $this->translation($id, 'en', $hiddenEnglish);
        self::assertSame([], $this->queued());
        $this->pages->removeTranslation($id, 'en');
        self::assertEmpty($this->queued(), 'removing a noindex translation does not announce its path');
    }
}
