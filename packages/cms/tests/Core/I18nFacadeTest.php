<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\I18n\I18n;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The facade with no kernel at all: it reads the files of the bundle in the language of useLocale(). tests/bootstrap.php sets ru for the whole suite. */
final class I18nFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::useLocale('ru');
        self::files(null);
    }

    /** @param array<string, string>|null $texts what the file itk_test.<language>.yaml of the bundle would hold; null takes it away */
    private static function files(?array $texts): void
    {
        $files = new \ReflectionProperty(I18n::class, 'files');
        $held = (array) $files->getValue();
        unset($held['itk_test.ru'], $held['itk_test.en']);
        $files->setValue(null, null === $texts ? $held : $held + ['itk_test.ru' => $texts, 'itk_test.en' => []]);
    }

    #[Test]
    public function aKeyGivesItsTextInTheChosenLanguage(): void
    {
        self::assertSame('Выберите язык из списка', I18n::t('i18n.locale_unknown_field'));
        I18n::useLocale('en');
        self::assertSame('Choose a language from the list', I18n::t('i18n.locale_unknown_field'));
        I18n::useLocale('kk');
        self::assertSame('Тілді тізімнен таңдаңыз', I18n::t('i18n.locale_unknown_field'));
        self::assertSame('kk', I18n::locale());
    }

    #[Test]
    public function parametersGoInWithoutTheirBraces(): void
    {
        self::assertSame('Заявка №12 не дошла в Telegram', I18n::t('i18n.notification_telegram_failed', ['id' => 12]));
        self::assertSame('Письмо не отправлено: Тест', I18n::t('i18n.notification_mail_failed', ['subject' => 'Тест']));
    }

    #[Test]
    public function aNumberChoosesTheRussianForm(): void
    {
        $forms = \array_map(static fn (int $count): string => I18n::t('i18n.plural_example', ['count' => $count]), [1, 2, 5, 11, 21, 22, 25, 0]);

        self::assertSame(['1 страница', '2 страницы', '5 страниц', '11 страниц', '21 страница', '22 страницы', '25 страниц', '0 страниц'], $forms);
    }

    #[Test]
    public function aNumberChoosesTheEnglishForm(): void
    {
        I18n::useLocale('en');

        self::assertSame('1 page', I18n::t('i18n.plural_example', ['count' => 1]));
        self::assertSame('2 pages', I18n::t('i18n.plural_example', ['count' => 2]));
        self::assertSame('0 pages', I18n::t('i18n.plural_example', ['count' => 0]));
    }

    /** Symfony has no rule for kk: it falls to the default, position 0. Not an error, and right for Kazakh, where a noun after a number stays singular. */
    #[Test]
    public function kazakhTakesTheFirstFormForAnyNumber(): void
    {
        I18n::useLocale('kk');

        foreach ([0, 1, 2, 5, 21, 100] as $count) {
            self::assertSame($count . ' бет', I18n::t('i18n.plural_example', ['count' => $count]));
        }
    }

    /** Symfony reads any text it is given %count% for as forms, so «Found:» would go as the label of the one form and only the number would stay. */
    #[Test]
    public function aNumberInATextWithoutFormsIsAParameterLikeAnyOther(): void
    {
        self::files(['test.found' => 'Found: {count}', 'test.selected' => 'Selected: {count} of {total}', 'test.none' => 'Ready']);

        self::assertSame('Found: 5', I18n::t('test.found', ['count' => 5]));
        self::assertSame('Found: 1', I18n::t('test.found', ['count' => 1]));
        self::assertSame('Selected: 1 of 3', I18n::t('test.selected', ['count' => 1, 'total' => 3]));
        self::assertSame('Ready', I18n::t('test.none', ['count' => 5]));
    }

    #[Test]
    public function aBarIsAPartOfTheTextWhenNoNumberIsGiven(): void
    {
        self::files(['test.bar' => 'Pages | {name}']);

        self::assertSame('Pages | Home', I18n::t('test.bar', ['name' => 'Home']));
    }

    /** The checks hold the catalogues of the bundle to the right forms; a file of a site is not checked, and a mistake in it costs a line in the log and not the answer. */
    #[Test]
    public function aTextWithoutEnoughFormsIsShownAsItIsAndNoError(): void
    {
        self::files(['test.short' => 'Страницы | {count}']);

        self::assertSame('Страницы | 5', I18n::t('test.short', ['count' => 5]));
    }

    #[Test]
    public function aLanguageWithoutTheFileFallsBackToEnglish(): void
    {
        I18n::useLocale('de');

        self::assertSame('Choose a language from the list', I18n::t('i18n.locale_unknown_field'));
    }

    #[Test]
    public function anUnknownKeyIsItselfAndNoError(): void
    {
        self::assertSame('i18n.no_such_key', I18n::t('i18n.no_such_key'));
        self::assertSame('no_area_at_all', I18n::t('no_area_at_all'));
        self::assertSame('nosuch.area', I18n::t('nosuch.area', ['name' => 'x']));
        self::assertFalse(I18n::has('i18n.no_such_key'));
        self::assertTrue(I18n::has('i18n.plural_example'));
    }

    #[Test]
    public function aValueThatIsNotTextLeavesNothingBehind(): void
    {
        self::assertSame('Письмо не отправлено: ', I18n::t('i18n.notification_mail_failed', ['subject' => ['a']]));
        self::assertSame('Письмо не отправлено: ', I18n::t('i18n.notification_mail_failed', ['subject' => null]));
    }

    #[Test]
    public function variantsAreTheTextInEveryLanguage(): void
    {
        self::assertSame(['Administrator', 'Администратор', 'Әкімші'], I18n::variants('i18n.role_admin_name'));
        self::assertSame([], I18n::variants('i18n.no_such_key'));
    }
}
