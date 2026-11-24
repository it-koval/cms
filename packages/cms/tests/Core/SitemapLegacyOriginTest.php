<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class SitemapLegacyOriginTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, itk: ['public_url' => '%env(ITK_TEST_LEGACY_PUBLIC_URL)%']);
    }

    /** @return iterable<string, array{string}> */
    public static function configuredOrigins(): iterable
    {
        yield 'domain only' => [''];
        yield 'bad optional public URL' => ['https://invalid.example/path'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('configuredOrigins')]
    public function domainOnlySingleLocaleXmlUsesTheNormalizedSeoOriginAndCanBeInvalidated(string $configured): void
    {
        $_SERVER['ITK_TEST_LEGACY_PUBLIC_URL'] = $_ENV['ITK_TEST_LEGACY_PUBLIC_URL'] = $configured;
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->beginTransaction();
        $sitemap = self::getContainer()->get(SitemapBuilder::class);
        try {
            $page = new Page('default');
            $version = new PageTranslation($page, 'ru');
            $version->publishedUrl = '/f2-legacy-origin-' . \bin2hex(\random_bytes(4));
            $version->published = $version->draft = ['title' => 'Legacy origin', 'url' => $version->publishedUrl, 'seo' => [], 'fields' => [], 'sections' => []];
            $version->status = PageTranslation::PUBLISHED;
            $version->publishedAt = new \DateTimeImmutable();
            $page->translations->set('ru', $version);
            $em->persist($page);
            $em->persist($version);
            $em->flush();
            $settings = self::getContainer()->get(SettingsService::class);
            foreach (['Пример.рф' => 'https://xn--e1afmkfd.xn--p1ai', 'http://Example.COM:80/' => 'http://example.com', 'Example.COM:443' => 'https://example.com'] as $domain => $origin) {
                $settings->set('domain', $domain);
                $sitemap->forget();
                self::assertStringContainsString('<loc>' . $origin . $version->publishedUrl . '</loc>', (string) $sitemap->xml());
                self::assertSame($origin, $sitemap->address()?->origin(), 'XML, host vouching and IndexNow share the normalized origin');
                self::assertSame($sitemap->address()?->origin(), $sitemap->base());
                $version->published = null;
                $em->flush();
                $sitemap->forget();
                self::assertStringNotContainsString($version->publishedUrl, (string) $sitemap->xml(), 'forget refreshes the normalized XML cache entry');
                $version->published = $version->draft;
                $em->flush();
                $sitemap->forget();
            }

            $legacy = $em->find(Setting::class, ['key' => 'domain', 'locale' => Setting::ALL]);
            self::assertInstanceOf(Setting::class, $legacy);
            // This value was accepted by 0.2.2 and may still be in the database after an upgrade.
            $legacy->value = 'http://Legacy.Example///';
            $em->flush();
            $settings->reset();
            self::assertNull($sitemap->xml(), 'an invalid legacy value is not an SEO origin');
            self::assertNull($sitemap->address());
        } finally {
            $sitemap->forget();
            $db->rollBack();
            unset($_SERVER['ITK_TEST_LEGACY_PUBLIC_URL'], $_ENV['ITK_TEST_LEGACY_PUBLIC_URL']);
        }
    }
}
