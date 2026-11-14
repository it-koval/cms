<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\LiveUrlIndex;
use Itk\Cms\Content\MenuService;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** A page saved with a trailing slash is canonical with it: links, menus, the sitemap and the address check follow the stored spelling. */
final class PageSlashTest extends WebTestCase
{
    /** @var list<string> */
    private array $ids = [];
    private ?string $area = null;

    protected function setUp(): void
    {
        static::createClient();
    }

    protected function tearDown(): void
    {
        $c = self::getContainer();
        if (null !== $this->area) {
            $c->get(MenuService::class)->removeArea($this->area);
        }
        foreach ($this->ids as $id) {
            try {
                $c->get(PageService::class)->remove($id);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    private function publish(string $url): string
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => 'Слэш ' . $url, 'url' => $url]);
        $this->ids[] = $page['id'];
        $pages->publish($page['id']);

        return $page['id'];
    }

    #[Test]
    public function aPathRefIsSpelledAsTheLivePageIsStoredWithQueryAndFragmentKept(): void
    {
        $this->publish('/test-slash-ref/');
        $this->publish('/test-flat-ref');
        $index = self::getContainer()->get(LiveUrlIndex::class);

        self::assertSame('/test-slash-ref/', $index->spelled('ru', '/test-slash-ref'));
        self::assertSame('/test-slash-ref/', $index->spelled('ru', '/Test-Slash-Ref/'));
        self::assertSame('/test-slash-ref/?d=1#x', $index->spelled('ru', '/test-slash-ref?d=1#x'));
        self::assertSame('/test-flat-ref', $index->spelled('ru', '/test-flat-ref/'));
        self::assertSame('/test-nope/', $index->spelled('ru', '/test-nope/'), 'an unknown path stays as written');
        self::assertSame('/test-nope', $index->spelled('ru', '/test-nope'));
    }

    #[Test]
    public function aPageMenuItemGetsTheStoredSpelling(): void
    {
        $this->publish('/test-slash-menu/');
        $menus = self::getContainer()->get(MenuService::class);
        $area = $menus->createArea('Test slash menu');
        $this->area = $area->key;
        $menus->saveMenus([$area->key => [['label' => 'A', 'link' => ['kind' => 'page', 'page' => '/test-slash-menu']]]]);

        self::assertSame('/test-slash-menu/', $menus->resolve($area->key)[0]['url']);
    }

    #[Test]
    public function aSlashAndASlashlessAddressAreOneAddress(): void
    {
        $this->publish('/test-slash-taken/');
        $pages = self::getContainer()->get(PageService::class);

        $this->expectException(ContentException::class);
        $pages->create(['template' => 'default', 'title' => 'Двойник', 'url' => '/test-slash-taken']);
    }

    #[Test]
    public function theSitemapListsTheStoredSpelling(): void
    {
        $this->publish('/test-slash-map/');
        $this->publish('/test-flat-map');
        $c = self::getContainer();
        $settings = $c->get(SettingsService::class);
        $was = $settings->isSet('domain') ? $settings->get('domain', null, '') : null;
        $settings->set('domain', 'test-slash-map.example.com');
        $sitemap = $c->get(SitemapBuilder::class);
        $sitemap->forget();

        try {
            $xml = (string) $sitemap->xml();
            self::assertStringContainsString('https://test-slash-map.example.com/test-slash-map/<', $xml);
            self::assertStringContainsString('https://test-slash-map.example.com/test-flat-map<', $xml);
        } finally {
            $settings->set('domain', $was ?? '');
            $sitemap->forget();
        }
    }
}
