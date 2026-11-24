<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Translation\DataCollectorTranslator;

/** What a site chooses: the options itk_cms.admin_locale(s), its own translations/, a translator of its own or none. Each test boots a kernel of that site. */
final class I18nSiteConfigTest extends TestCase
{
    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        I18n::useLocale('ru');
    }

    /**
     * @param array<string, mixed> $framework
     * @param array<string, mixed> $itk
     */
    private function boot(array $framework = [], array $itk = []): TestKernel
    {
        $this->kernel = new TestKernel('test', true, null, '', $framework + ['default_locale' => 'en'], [], $itk);
        $this->kernel->boot();

        return $this->kernel;
    }

    #[Test]
    public function byDefaultThePanelSpeaksEnglishAndOffersEveryLanguage(): void
    {
        $locale = $this->boot(itk: ['admin_locale' => 'en'])->getContainer()->get(AdminLocale::class);

        self::assertSame('en', $locale->default());
        self::assertSame(['en', 'ru', 'kk'], $locale->available());
        self::assertSame('Choose a language from the list', I18n::t('i18n.locale_unknown_field'));
    }

    #[Test]
    public function aSiteNarrowsTheList(): void
    {
        $locale = $this->boot(itk: ['admin_locale' => 'kk', 'admin_locales' => ['kk', 'ru']])->getContainer()->get(AdminLocale::class);

        self::assertSame(['kk', 'ru'], $locale->available());
        self::assertSame([['code' => 'kk', 'name' => 'Қазақша'], ['code' => 'ru', 'name' => 'Русский']], $locale->choices());
        self::assertSame('Choose a language from the list', I18n::t('i18n.locale_unknown_field'), 'the console and the worker keep framework.default_locale');
    }

    #[Test]
    public function whatIsSavedWithTheDataOfTheSiteIsInTheLanguageOfItsContent(): void
    {
        $locale = $this->boot(['default_locale' => 'ru'], itk: ['admin_locale' => 'ru', 'main_locale' => 'en'])->getContainer()->get(AdminLocale::class);

        self::assertSame('en', $locale->content());
        self::assertSame('Home', $locale->runContent(static fn (): string => I18n::t('model.core_home_name')));
        self::assertSame('ru', I18n::locale(), 'the language of the person is put back');
        self::assertSame('Главная', I18n::t('model.core_home_name'));
    }

    #[Test]
    public function aContentLanguageWithoutACatalogueFallsBackToTheAdminOne(): void
    {
        $locale = $this->boot(itk: ['admin_locale' => 'kk', 'main_locale' => 'de'])->getContainer()->get(AdminLocale::class);

        self::assertSame('kk', $locale->content());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function wrongOptions(): iterable
    {
        yield 'a language the panel has no catalogue for' => [['admin_locales' => ['ru', 'de'], 'admin_locale' => 'ru'], 'not translated into "de"'];
        yield 'a default outside the list' => [['admin_locale' => 'en', 'admin_locales' => ['ru', 'kk']], 'itk_cms.admin_locale "en" is not one of itk_cms.admin_locales (ru, kk)'];
        yield 'an empty list' => [['admin_locales' => []], 'admin_locales'];
    }

    /** @param array<string, mixed> $itk */
    #[Test]
    #[DataProvider('wrongOptions')]
    public function aWrongOptionStopsTheBuildAndSaysWhy(array $itk, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new TestKernel('test', true, null, '', [], [], $itk))->boot();
    }

    #[Test]
    public function aSiteOverridesOneTextOfADomainWithItsOwnFile(): void
    {
        $this->boot(['default_locale' => 'ru', 'translator' => ['default_path' => __DIR__ . '/Fixture/translations']]);

        self::assertSame('Свой текст сайта', I18n::t('i18n.locale_unknown_field'), 'its text');
        self::assertSame('Заявка №3 не дошла в Telegram', I18n::t('i18n.notification_telegram_failed', ['id' => 3]), 'the other texts of the domain stay the bundle\'s');
    }

    #[Test]
    public function aSiteWithoutATranslatorStillGetsTheTextsOfTheBundle(): void
    {
        $locale = $this->boot(['default_locale' => 'ru', 'translator' => false], itk: ['admin_locale' => 'en'])->getContainer()->get(AdminLocale::class);

        self::assertSame('Выберите язык из списка', I18n::t('i18n.locale_unknown_field'));
        self::assertSame('kk', $locale->run('kk', static fn (): string => I18n::locale()));
        self::assertSame('Тілді тізімнен таңдаңыз', $locale->run('kk', static fn (): string => I18n::t('i18n.locale_unknown_field')));
        self::assertSame('ru', I18n::locale());
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function fallbacks(): iterable
    {
        yield 'a site without a list: its default language, then English' => [['default_locale' => 'ru'], ['ru', 'en']];
        yield 'a site with its own list: the list, then English' => [['default_locale' => 'ru', 'translator' => ['fallbacks' => ['ru', 'kk']]], ['ru', 'kk', 'en']];
        yield 'a list with English keeps its order' => [['translator' => ['fallbacks' => ['en', 'ru']]], ['en', 'ru']];
        yield 'an English site' => [[], ['en']];
    }

    /**
     * The fallbacks of the books of a site stay the ones of the site: English only goes behind them, for the texts of the bundle.
     *
     * @param array<string, mixed> $framework
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('fallbacks')]
    public function aSiteKeepsItsOwnFallbacks(array $framework, array $expected): void
    {
        $translator = $this->boot($framework)->getContainer()->get('translator');
        self::assertInstanceOf(DataCollectorTranslator::class, $translator);

        self::assertSame($expected, $translator->getFallbackLocales());
    }

    #[Test]
    public function aBookOfASiteWithoutAKeyInOneLanguageFallsBackToTheLanguageOfTheSite(): void
    {
        $translator = $this->boot(['default_locale' => 'ru', 'translator' => ['default_path' => __DIR__ . '/Fixture/translations']])->getContainer()->get('translator');
        self::assertInstanceOf(DataCollectorTranslator::class, $translator);

        self::assertSame('Текст сайта', $translator->trans('site.text', [], 'site', 'kk'));
    }

    #[Test]
    public function aNumberDoesNotEatATextOfASite(): void
    {
        $container = $this->boot(['default_locale' => 'ru', 'translator' => ['default_path' => __DIR__ . '/Fixture/translations']])->getContainer();

        self::assertSame('Found: 5', I18n::t('i18n.site_found', ['count' => 5]));
        self::assertSame('Пункт | 5', I18n::t('i18n.site_short', ['count' => 5]), 'too few forms: the text as it is');
        $problems = \array_filter($container->get('logger')->records, static fn (array $r): bool => 'warning' === $r['level'] && 'i18n.site_short' === ($r['context']['key'] ?? null));
        self::assertCount(1, $problems, 'one line in the log');
    }
}
