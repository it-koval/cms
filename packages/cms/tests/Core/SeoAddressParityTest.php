<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SeoAddressCheck;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\Revision;
use Itk\Cms\Entity\User;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class SeoAddressParityTest extends WebTestCase
{
    private const PUBLIC_BASE = 'https://seo-parity.example.test';

    /** @var list<string> */
    private array $pages = [];
    /** @var list<string> */
    private array $rules = [];
    private ?string $user = null;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $itk = ['main_locale' => 'ru', 'public_url' => self::PUBLIC_BASE, 'page_cache' => ['enabled' => false]];
        if ($options['multilingual'] ?? true) {
            $itk['locales'] = ['ru' => ['name' => 'Russian'], 'kk' => ['name' => 'Kazakh']];
        }

        return new TestKernel('test', true, itk: $itk);
    }

    protected function tearDown(): void
    {
        try {
            $c = self::getContainer();
            $em = $c->get(EntityManagerInterface::class);
            $em->clear();
            if ([] !== $this->pages) {
                $em->createQuery('DELETE FROM ' . Page::class . ' p WHERE p.id IN (:ids)')->setParameter('ids', $this->pages)->execute();
                $em->createQuery('DELETE FROM ' . Revision::class . ' r WHERE r.kind = :kind AND r.resourceId IN (:ids)')->setParameter('kind', Revision::PAGE)->setParameter('ids', $this->pages)->execute();
            }
            foreach ($this->rules as $id) {
                $c->get(SeoRuleService::class)->remove($id);
            }
            if (null !== $this->user) {
                $em->createQuery('DELETE FROM ' . User::class . ' u WHERE u.id = :id')->setParameter('id', $this->user)->execute();
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return iterable<array-key, mixed> */
    public static function localeModes(): iterable
    {
        yield 'single language' => [false];
        yield 'multiple languages' => [true];
    }

    #[Test]
    #[DataProvider('localeModes')]
    public function canonicalRedirectKeepsTheOriginalQuery(bool $multilingual): void
    {
        $client = static::createClient(['multilingual' => $multilingual]);
        $path = $this->publish($multilingual ? 'kk' : 'ru');
        $query = 'tag=x&tag=y&z=%2b&flag';
        $requested = $path . '/?' . $query;
        $expected = $path . '?' . $query;

        $client->request('GET', $requested);
        $check = self::getContainer()->get(SeoAddressCheck::class)->check($requested);
        self::assertSame([301, $expected], [$client->getResponse()->getStatusCode(), $client->getResponse()->headers->get('Location')]);
        self::assertSame([301, $expected], [$check['answer']['status'], $check['answer']['to'] ?? null]);
    }

    #[Test]
    public function theRuleFilterDoesNotChangeTheRenderedSeoAndCanonical(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $path = $this->publish('kk');
        $rules = self::getContainer()->get(SeoRuleService::class);
        $russian = $rules->create(['path' => $path, 'locale' => 'ru', 'title' => 'Russian rule']);
        $this->rules[] = $russian->id;
        $kazakh = $rules->create(['path' => $path, 'locale' => 'kk', 'title' => 'Kazakh rule']);
        $this->rules[] = $kazakh->id;
        $user = self::getContainer()->get(UserManager::class)->create('seo-parity-' . \bin2hex(\random_bytes(6)) . '@example.test', 'SEO parity', 'admin', 'password-for-test');
        $this->user = $user->id;
        $client->loginUser($user, 'itk_admin');

        foreach ([self::PUBLIC_BASE . $path, self::PUBLIC_BASE . $path . '-preferred'] as $i => $canonical) {
            if (1 === $i) {
                $rules->update($kazakh->id, ['canonical' => $path . '-preferred']);
            }
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('<title>Kazakh rule</title>', $html);
            self::assertStringContainsString('rel="canonical" href="' . $canonical . '"', $html);

            $client->request('GET', '/admin/api/seo-rules/check?path=' . \rawurlencode($path) . '&locale=ru');
            self::assertResponseIsSuccessful();
            $check = \json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame([$russian->id], \array_column($check['rules'], 'id'));
            self::assertFalse($check['rules'][0]['winner']);
            self::assertSame(['value' => 'Kazakh rule', 'source' => 'rule'], $check['meta']['title']);
            self::assertSame(['value' => $canonical, 'source' => 0 === $i ? 'page' : 'rule'], $check['meta']['canonical']);
        }
    }

    #[Test]
    #[DataProvider('siteRouteLocales')]
    public function aSiteRouteUsesItsExplicitLanguageOrPathPrefixForSeo(string $path, string $title): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $rules = self::getContainer()->get(SeoRuleService::class);
        $this->rules[] = $rules->create(['path' => $path, 'locale' => 'ru', 'title' => 'Russian rule'])->id;
        $this->rules[] = $rules->create(['path' => $path, 'locale' => 'kk', 'title' => 'Kazakh rule'])->id;

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<title>' . $title . '</title>', (string) $client->getResponse()->getContent());

        $check = self::getContainer()->get(SeoAddressCheck::class)->check($path);
        self::assertSame('route', $check['answer']['kind']);
        self::assertSame(['value' => $title, 'source' => 'rule'], $check['meta']['title']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function siteRouteLocales(): iterable
    {
        yield 'path prefix' => ['/kk/test-analytics/controller', 'Kazakh rule'];
        yield 'explicit route locale' => ['/kk/test-analytics/explicit-locale', 'Russian rule'];
    }

    #[Test]
    public function encodedRuleAndRequestPathsMatchTheSameCyrillicRoute(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $path = '/kk/кафе';
        $encoded = '/kk/' . \rawurlencode('кафе');
        $c = self::getContainer();
        $rule = $c->get(SeoRuleService::class)->create(['path' => $encoded, 'locale' => 'kk', 'title' => 'Cafe rule']);
        $this->rules[] = $rule->id;
        self::assertSame($path, $rule->path);

        $client->request('GET', $encoded);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<title>Cafe rule</title>', (string) $client->getResponse()->getContent());
        $check = $c->get(SeoAddressCheck::class)->check($encoded);
        self::assertSame($path, $check['path']);
        self::assertSame(['value' => 'Cafe rule', 'source' => 'rule'], $check['meta']['title']);
    }

    #[Test]
    public function anEncodedSlashNeverSharesTheNestedPathSeoRule(): void
    {
        static::createClient();
        $c = self::getContainer();
        $nested = '/guides/summer';
        $encoded = '/guides%2Fsummer';
        $this->rules[] = $c->get(SeoRuleService::class)->create(['path' => $nested, 'robots' => 'noindex,follow'])->id;
        $pages = $c->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'locale' => 'ru', 'url' => $encoded, 'title' => 'Summer']);
        $this->pages[] = $page['id'];
        $pages->publish($page['id'], 'ru');

        $seo = $c->get(\Itk\Cms\Content\SeoResolver::class);
        self::assertSame('noindex,follow', $seo->forRequest(\Symfony\Component\HttpFoundation\Request::create($nested))?->robots);
        self::assertNull($seo->forRequest(\Symfony\Component\HttpFoundation\Request::create($encoded)));
        $sitemap = $c->get(\Itk\Cms\Content\IndexablePages::class)->rows();
        self::assertContains($page['id'], \array_column($sitemap, 'pageId'));
        self::assertSame([], $c->get(SeoAddressCheck::class)->check($encoded)['rules']);
        self::assertSame('/guides%2fsummer', SeoRuleService::normalizePath($encoded));
    }

    #[Test]
    public function anEncodedRootMatchesThePublicHome(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $c = self::getContainer();
        $db = $c->get(Connection::class);
        $db->beginTransaction();
        try {
            if (null === $c->get(PageTranslationRepository::class)->findLive('ru', '/')) {
                $page = $c->get(\Itk\Cms\Repository\PageRepository::class)->findOneBy(['template' => 'home']);
                self::assertNotNull($page);
                $c->get(PageService::class)->publish($page->id);
            }
            $client->request('GET', '/%2F');
            self::assertResponseStatusCodeSame(200);
            $check = $c->get(SeoAddressCheck::class)->check('/%2F');
            self::assertSame(200, $check['answer']['status']);
            self::assertSame('page', $check['answer']['kind']);
        } finally {
            $db->rollBack();
            $c->get(EntityManagerInterface::class)->clear();
        }
    }

    private function publish(string $locale): string
    {
        $path = ('ru' === $locale ? '' : '/' . $locale) . '/test-seo-parity-' . \bin2hex(\random_bytes(6));
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'locale' => $locale, 'url' => $path, 'title' => 'SEO parity']);
        $this->pages[] = $page['id'];
        $pages->publish($page['id'], $locale);

        return $path;
    }
}
