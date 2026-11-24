<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\LiveUrlIndex;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\Redirect;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\TemporarySettings;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

final class PageCacheLocalesTest extends WebTestCase
{
    use TemporarySettings;

    private const SAME_SECOND = '2026-01-01 00:00:00';

    private KernelBrowser $client;
    private string $path;
    /** @var list<string> */
    private array $pageIds = [];
    private ?string $ruleId = null;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', false, itk: [
            'main_locale' => 'ru',
            'locales' => [
                'ru' => ['name' => 'Русский'],
                'kk' => ['name' => 'Қазақша'],
                'tr' => ['name' => 'Türkçe'],
                'zh' => ['name' => '中文', 'html_lang' => 'zh-CN'],
                'en' => ['name' => 'English'],
            ], // i18n-ignore: configured language names
        ]);
    }

    protected function setUp(): void
    {
        $this->path = '/test-cache-locales-' . \bin2hex(\random_bytes(6));
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->setting('domain', 'http://localhost');
    }

    protected function tearDown(): void
    {
        try {
            $c = self::getContainer();
            $em = $c->get(EntityManagerInterface::class);
            $em->clear();
            if ([] !== $this->pageIds) {
                $em->createQuery('DELETE FROM ' . Page::class . ' p WHERE p.id IN (:ids)')->setParameter('ids', $this->pageIds)->execute();
                $em->createQuery('DELETE FROM ' . Revision::class . ' r WHERE r.kind = :kind AND r.resourceId IN (:ids)')->setParameter('kind', Revision::PAGE)->setParameter('ids', $this->pageIds)->execute();
            }
            $em->createQuery('DELETE FROM ' . Redirect::class . ' r WHERE r.fromPath = :path')->setParameter('path', '/kk' . $this->path)->execute();
            if (null !== $this->ruleId) {
                $c->get(SeoRuleService::class)->remove($this->ruleId);
            }
            $c->get(PageCache::class)->clear();
        } finally {
            try {
                $this->restoreSettings();
            } finally {
                parent::tearDown();
            }
        }
    }

    #[Test]
    public function aSameSecondNoindexRuleChangeInvalidatesTheOtherLanguage(): void
    {
        $c = self::getContainer();
        $pages = $c->get(PageService::class);
        $id = $this->publish($this->path);
        $kk = '/kk' . $this->path;
        $pages->addTranslation($id, 'kk', 'ru');
        $pages->publish($id, 'kk');
        $rules = $c->get(SeoRuleService::class);
        $rule = $rules->create(['path' => $kk, 'locale' => 'kk', 'robots' => 'index,follow']);
        $this->ruleId = $rule->id;
        $this->setRuleSecond($rule->id);
        $before = $this->visit($this->path);
        self::assertStringContainsString('hreflang="kk"', (string) $before->getContent());

        $rules->update($rule->id, ['robots' => 'noindex,follow']);
        $this->setRuleSecond($rule->id);
        self::assertStringNotContainsString('hreflang=', (string) $this->updated($this->tag($before))->getContent());
    }

    #[Test]
    public function publicationAndAddressChangesInAnotherLanguageReplaceTheCachedHtmlAndTag(): void
    {
        $pages = self::getContainer()->get(PageService::class);
        $id = $this->publish($this->path);
        $independentPath = $this->path . '-independent';
        $this->publish($independentPath);
        $pages->addTranslation($id, 'kk', 'ru');

        $original = $this->visit($this->path);
        self::assertStringNotContainsString('hreflang=', (string) $original->getContent());
        self::assertSame('HIT', $this->visit($this->path)->headers->get('X-ITK-Cache'));
        $independent = $this->tag($this->visit($independentPath));
        $tag = $this->tag($original);

        $kk = '/kk' . $this->path;
        $moved = $kk . '-moved';
        $pages->publish($id, 'kk');
        $this->setKazakhPublicationSecond($id);
        $published = $this->updated($tag);
        self::assertSame('MISS', $published->headers->get('X-ITK-Cache'));
        self::assertStringContainsString('hreflang="kk" href="http://localhost' . $kk . '"', (string) $published->getContent());
        self::assertSame(304, $this->visit($independentPath, $independent)->getStatusCode());

        $tag = $this->tag($published);
        $pages->saveDraft($id, 'kk', ['url' => $moved]);
        self::assertSame(304, $this->visit($this->path, $tag)->getStatusCode(), 'an autosave does not change the live version');
        $pages->publish($id, 'kk');
        $this->setKazakhPublicationSecond($id);
        $renamed = $this->updated($tag);
        self::assertSame('MISS', $renamed->headers->get('X-ITK-Cache'));
        self::assertStringContainsString('hreflang="kk" href="http://localhost' . $moved . '"', (string) $renamed->getContent());
        self::assertStringNotContainsString('http://localhost' . $kk . '"', (string) $renamed->getContent());
        self::assertSame(304, $this->visit($independentPath, $independent)->getStatusCode());

        $pages->unpublish($id, 'kk');
        $removed = $this->updated($this->tag($renamed));
        self::assertStringNotContainsString('hreflang=', (string) $removed->getContent());
        self::assertSame(304, $this->visit($independentPath, $independent)->getStatusCode());
    }

    #[Test]
    public function theWarmLiveAddressIndexIsRefreshedAfterChangingAnyPublishedLanguage(): void
    {
        $pages = self::getContainer()->get(PageService::class);
        $index = self::getContainer()->get(LiveUrlIndex::class);
        $id = $this->publish($this->path);
        $pages->addTranslation($id, 'kk', 'ru');
        $kk = '/kk' . $this->path;
        $moved = $kk . '-moved';
        self::assertNull($index->byId('kk', $id));
        self::assertFalse($index->has('kk', $kk));
        self::assertTrue($index->has('ru', $this->path));

        $pages->massPublish([$id], 'kk');
        self::assertSame($id, $index->find('kk', $kk)['id'] ?? null);
        $pages->saveDraft($id, 'kk', ['url' => $moved]);
        self::assertTrue($index->has('kk', $kk));
        self::assertFalse($index->has('kk', $moved));
        $pages->publish($id, 'kk');
        self::assertFalse($index->has('kk', $kk));
        self::assertSame($moved, $index->byId('kk', $id)['url'] ?? null);
        $pages->unpublish($id, 'kk');
        self::assertNull($index->byId('kk', $id));
        self::assertFalse($index->has('kk', $moved));
        $pages->republish($id, 'kk');
        self::assertTrue($index->has('kk', $moved));
        $pages->remove($id);
        self::assertFalse($index->has('ru', $this->path));
        self::assertFalse($index->has('kk', $moved));
    }

    private function publish(string $path): string
    {
        $pages = self::getContainer()->get(PageService::class);
        $id = $pages->create(['template' => 'default', 'title' => 'Cache locales', 'url' => $path])['id'];
        $this->pageIds[] = $id;
        $pages->publish($id);

        return $id;
    }

    private function setKazakhPublicationSecond(string $id): void
    {
        $c = self::getContainer();
        // Pin the stored timestamp of both committed publications so only the published URL differs.
        $c->get(Connection::class)->executeStatement(
            'UPDATE itk_page_translation SET published_at = :stamp WHERE page_id = :id AND locale = :locale',
            ['stamp' => self::SAME_SECOND, 'id' => $id, 'locale' => 'kk'],
        );
        $c->get(EntityManagerInterface::class)->clear();
    }

    private function setRuleSecond(string $id): void
    {
        $c = self::getContainer();
        $c->get(Connection::class)->executeStatement(
            'UPDATE itk_seo_rule SET updated_at = :stamp WHERE id = :id',
            ['stamp' => self::SAME_SECOND, 'id' => $id],
        );
        $c->get(EntityManagerInterface::class)->clear();
    }

    private function visit(string $path, ?string $tag = null): Response
    {
        $this->client->request('GET', $path, server: null === $tag ? [] : ['HTTP_IF_NONE_MATCH' => $tag]);

        return $this->client->getResponse();
    }

    private function tag(Response $response): string
    {
        $tag = $response->getEtag();
        self::assertNotNull($tag);

        return $tag;
    }

    private function updated(string $oldTag): Response
    {
        $response = $this->visit($this->path, $oldTag);
        self::assertSame(200, $response->getStatusCode());
        $newTag = $this->tag($response);
        self::assertNotSame($oldTag, $newTag);
        self::assertSame(304, $this->visit($this->path, $newTag)->getStatusCode());

        return $response;
    }
}
