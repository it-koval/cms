<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ContentLocalesConfigTest extends TestCase
{
    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
    }

    #[Test]
    public function buildsThePublicServiceAndPutsTheMainLanguageFirst(): void
    {
        $container = $this->boot([
            'main_locale' => ' RU ',
            'locales' => [
                'kk' => ['name' => 'Kazakh'],
                'tr' => ['name' => 'Turkish'],
                'ru' => ['name' => 'Russian'],
                'zh' => ['name' => 'Chinese', 'html_lang' => 'zh-CN', 'og_locale' => 'zh_CN'],
                'en' => ['name' => 'English'],
            ],
        ]);
        $locales = $container->get(ContentLocales::class);

        self::assertInstanceOf(ContentLocales::class, $locales);
        self::assertSame('ru', $container->getParameter('itk_cms.main_locale'));
        self::assertSame([
            'ru' => ['name' => 'Russian', 'html_lang' => null, 'og_locale' => null],
            'kk' => ['name' => 'Kazakh', 'html_lang' => null, 'og_locale' => null],
            'tr' => ['name' => 'Turkish', 'html_lang' => null, 'og_locale' => null],
            'zh' => ['name' => 'Chinese', 'html_lang' => 'zh-CN', 'og_locale' => 'zh_CN'],
            'en' => ['name' => 'English', 'html_lang' => null, 'og_locale' => null],
        ], $container->getParameter('itk_cms.locales'));
        self::assertTrue($locales->isConfigured());
        self::assertSame(['ru', 'kk', 'tr', 'zh', 'en'], $locales->codes());
        self::assertSame('Russian', $locales->name('ru'));
    }

    #[Test]
    public function omittingTheListKeepsTheLegacyMainLanguage(): void
    {
        $container = $this->boot(['main_locale' => 'pt-BR']);
        $locales = $container->get(ContentLocales::class);

        self::assertInstanceOf(ContentLocales::class, $locales);
        self::assertSame('pt-BR', $container->getParameter('itk_cms.main_locale'));
        self::assertSame(['pt-BR' => ['name' => 'pt-BR', 'html_lang' => null, 'og_locale' => null]], $container->getParameter('itk_cms.locales'));
        self::assertFalse($locales->isConfigured());
        self::assertSame(['pt-BR'], $locales->codes());
    }

    #[Test]
    public function anExplicitEmptyListKeepsOnlyTheMainLanguage(): void
    {
        $container = $this->boot(['main_locale' => 'en', 'locales' => []]);
        $locales = $container->get(ContentLocales::class);

        self::assertInstanceOf(ContentLocales::class, $locales);
        self::assertTrue($locales->isConfigured());
        self::assertSame(['en'], $locales->codes());
        self::assertSame(['en' => ['name' => 'English', 'html_lang' => null, 'og_locale' => null]], $container->getParameter('itk_cms.locales'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'missing main' => [['main_locale' => 'en', 'locales' => ['ru' => ['name' => 'Russian']]], 'itk_cms.main_locale "en"'];
        yield 'uppercase code' => [['locales' => ['ru' => ['name' => 'Russian'], 'KZ' => ['name' => 'Kazakh']]], 'Invalid language code "KZ"'];
        yield 'html_lang' => [['locales' => ['ru' => ['name' => 'Russian', 'html_lang' => 'ru_RU']]], 'html_lang'];
        yield 'og_locale' => [['locales' => ['ru' => ['name' => 'Russian', 'og_locale' => 'ru-RU']]], 'og_locale'];
        yield 'missing name' => [['locales' => ['ru' => []]], 'name'];
        yield 'blank name' => [['locales' => ['ru' => ['name' => '   ']]], 'Language name'];
        yield 'non-string name' => [['locales' => ['ru' => ['name' => 123]]], 'Language name'];
        yield 'duplicate hreflang with implicit html_lang' => [['locales' => ['ru' => ['name' => 'Russian'], 'kk' => ['name' => 'Kazakh', 'html_lang' => 'ru']]], 'same html_lang "ru"'];
        yield 'duplicate hreflang with different case' => [['locales' => ['ru' => ['name' => 'Russian', 'html_lang' => 'ru-RU'], 'kk' => ['name' => 'Kazakh', 'html_lang' => 'ru-ru']]], 'same html_lang "ru-ru"'];
    }

    /** @param array<string, mixed> $options */
    #[Test]
    #[DataProvider('invalidOptions')]
    public function rejectsInvalidConfiguration(array $options, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->boot($options);
    }

    /** @param array<string, mixed> $options */
    private function boot(array $options): ContainerInterface
    {
        $this->kernel = new TestKernel('test', true, itk: $options);
        $this->kernel->boot();

        return $this->kernel->getContainer();
    }
}
