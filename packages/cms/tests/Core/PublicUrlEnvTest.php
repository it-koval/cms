<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpKernel\KernelInterface;

/** itk_cms.public_url принимает %env()%: формат проверяется после подстановки, пустое значение — как будто опции нет. */
final class PublicUrlEnvTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], ['public_url' => $options['public_url']] + ($options['itk'] ?? []));
    }

    protected function tearDown(): void
    {
        unset($_SERVER['ITK_TEST_PUBLIC_URL'], $_ENV['ITK_TEST_PUBLIC_URL']);
        parent::tearDown();
    }

    /** @param array<string, mixed> $itk */
    private function boot(string $value, array $itk = []): PublicUrl
    {
        $_SERVER['ITK_TEST_PUBLIC_URL'] = $_ENV['ITK_TEST_PUBLIC_URL'] = $value;
        self::bootKernel(['public_url' => '%env(ITK_TEST_PUBLIC_URL)%', 'itk' => $itk]);

        return self::getContainer()->get(PublicUrl::class);
    }

    #[Test]
    public function anAddressFromTheEnvironmentIsUsed(): void
    {
        self::assertSame('https://example.org', $this->boot('https://example.org/')->base());
    }

    #[Test]
    public function publicUrlAloneIsAnAsciiSiteOriginForTheSitemap(): void
    {
        $origin = 'https://xn----8sbarojrwjdmo.xn--p1ai';
        $publicUrl = $this->boot('https://Пример-Сайта.рф:443/');
        $sitemap = self::getContainer()->get(SitemapBuilder::class);
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            self::getContainer()->get(SettingsService::class)->set('domain', '');
            self::assertSame($origin, $publicUrl->base());
            self::assertSame($origin, $sitemap->base());
            self::assertSame($origin, $sitemap->address()?->origin());
            self::assertStringContainsString('<urlset', (string) $sitemap->xml());
        } finally {
            $sitemap->forget();
            $db->rollBack();
        }
    }

    #[Test]
    public function invalidOptionalPublicUrlDoesNotBreakSitemapOrHostVouching(): void
    {
        $this->boot('https://invalid.example/path');
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $settings = self::getContainer()->get(SettingsService::class);
            $sitemap = self::getContainer()->get(SitemapBuilder::class);
            $settings->set('domain', 'legacy.example.com');
            self::assertSame('https://legacy.example.com', self::getContainer()->get(PublicUrl::class)->base(strict: false));
            self::assertSame('https://legacy.example.com', $sitemap->base());
            self::assertSame('https://legacy.example.com', $sitemap->address()?->origin());
            self::assertStringContainsString('<urlset', (string) $sitemap->xml());
            $settings->set('domain', '');
            self::assertNull($sitemap->base());
            self::assertNull($sitemap->address());
            self::assertNull($sitemap->xml());
        } finally {
            $db->rollBack();
        }
    }

    #[Test]
    public function invalidOptionalOriginStillRendersMultilingualPagesAndCriticalCssSource(): void
    {
        $this->boot('https://invalid.example/path', ['locales' => ['ru' => ['name' => 'Russian'], 'en' => ['name' => 'English']]]);
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->beginTransaction();
        try {
            self::getContainer()->get(SettingsService::class)->set('domain', 'legacy.example.com');
            $page = new \Itk\Cms\Entity\Page('default');
            $version = new \Itk\Cms\Entity\PageTranslation($page, 'en');
            $version->draft = ['title' => 'Origin regression', 'url' => '/en/f2-origin-' . \bin2hex(\random_bytes(4)), 'seo' => [], 'sections' => [], 'fields' => []];
            $version->published = $version->draft;
            $version->publishedUrl = $version->draft['url'];
            $version->status = \Itk\Cms\Entity\PageTranslation::PUBLISHED;
            $version->publishedAt = new \DateTimeImmutable();
            $page->translations->set('en', $version);
            $em->persist($page);
            $em->persist($version);
            $em->flush();
            $requests = self::getContainer()->get(\Symfony\Component\HttpFoundation\RequestStack::class);
            $requests->push(\Symfony\Component\HttpFoundation\Request::create('https://legacy.example.com' . $version->publishedUrl));
            $renderer = self::getContainer()->get(\Itk\Cms\Rendering\PageRenderer::class);
            $live = (string) $renderer->renderLive($version)->getContent();
            self::assertStringContainsString('https://legacy.example.com' . $version->publishedUrl, $live);
            self::assertStringContainsString('https://legacy.example.com' . $version->publishedUrl, $renderer->renderSource($version));
        } finally {
            if (isset($requests)) {
                $requests->pop();
            }
            $db->rollBack();
        }
    }

    #[Test]
    public function configuredPublicOriginTakesPrecedenceOverTheLegacyDomain(): void
    {
        $this->boot('https://canonical.example.com');
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            self::getContainer()->get(SettingsService::class)->set('domain', 'legacy.example.com');
            self::assertSame('https://canonical.example.com', self::getContainer()->get(SitemapBuilder::class)->base());
        } finally {
            $db->rollBack();
        }
    }

    #[Test]
    public function aPathCannotBeSavedAsTheSiteDomain(): void
    {
        $this->boot('');
        $settings = self::getContainer()->get(SettingsService::class);
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $this->expectException(ContentException::class);
            $settings->update(['domain' => 'https://f2-domain.example/path']);
        } finally {
            $db->rollBack();
        }
    }

    #[Test]
    public function aWrongAddressFromTheEnvironmentIsRefusedWhenItIsRead(): void
    {
        $urls = $this->boot('example.org');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('itk_cms.public_url');
        $urls->base();
    }

    #[Test]
    public function anEmptyVariableIsAsIfTheOptionWasNotSet(): void
    {
        $urls = $this->boot('');
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            self::getContainer()->get(SettingsService::class)->set('domain', '');
            self::assertNull($urls->base(), 'no request, no «domain» setting: no trusted address, as without the option');
        } finally {
            $db->rollBack();
        }
    }

    #[Test]
    public function aWrongLiteralIsStillRefusedWhenTheContainerIsBuilt(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        self::bootKernel(['public_url' => 'example.org']);
    }

    #[Test]
    public function aGoodLiteralWorksAsBefore(): void
    {
        self::bootKernel(['public_url' => 'https://example.com/']);

        self::assertSame('https://example.com', self::getContainer()->get(PublicUrl::class)->base());
    }
}
