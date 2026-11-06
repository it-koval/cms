<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Analytics\AnalyticsConfig;
use Itk\Cms\Analytics\AnalyticsInjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Код подтверждения владельца и код, который выдал Яндекс Вебмастер, стоят на странице оба; тег с тем же именем и другим кодом не считается «уже есть». */
final class AnalyticsVerificationMetaTest extends TestCase
{
    private const OWNER = 'owner-code-1234';
    private const UIN = 'f00dcafe12345678';

    private function page(string $head = ''): string
    {
        return '<!doctype html><html><head><title>t</title>' . $head . '</head><body>x</body></html>';
    }

    private function times(string $html, string $code): int
    {
        return \substr_count($html, 'name="yandex-verification" content="' . $code . '"');
    }

    #[Test]
    public function theCodeOfTheCmsStandsNextToADifferentCodeOfTheOwner(): void
    {
        $html = AnalyticsInjector::inject($this->page(), AnalyticsConfig::fromSettings(['yandexVerification' => self::OWNER], self::UIN));

        self::assertSame([1, 1], [$this->times($html, self::OWNER), $this->times($html, self::UIN)]);
    }

    #[Test]
    public function theSameCodeIsWrittenOnce(): void
    {
        $html = AnalyticsInjector::inject($this->page(), AnalyticsConfig::fromSettings(['yandexVerification' => self::UIN], self::UIN));

        self::assertSame(1, $this->times($html, self::UIN));
    }

    #[Test]
    public function theCodeAloneWithoutAnOwnerSettingIsEnough(): void
    {
        $config = AnalyticsConfig::fromSettings([], self::UIN);

        self::assertFalse($config->isEmpty());
        self::assertSame(1, $this->times(AnalyticsInjector::inject($this->page(), $config), self::UIN));
    }

    #[Test]
    public function aTagThatIsAlreadyThereWithTheSameCodeIsNotDuplicatedWhateverTheAttributeOrder(): void
    {
        $own = $this->page('<meta content="' . self::UIN . '" name="yandex-verification"><meta name=yandex-verification content=' . self::OWNER . '>');
        $config = AnalyticsConfig::fromSettings(['yandexVerification' => self::OWNER], self::UIN);

        self::assertSame($own, AnalyticsInjector::inject($own, $config));
    }

    #[Test]
    public function aTagWithTheSameNameAndAnotherCodeDoesNotHideOurs(): void
    {
        $html = AnalyticsInjector::inject($this->page('<meta name="yandex-verification" content="someone-else-99">'), AnalyticsConfig::fromSettings([], self::UIN));

        self::assertSame(1, $this->times($html, self::UIN));
        self::assertSame(1, \substr_count($html, 'someone-else-99'));
    }

    #[Test]
    public function anAttributeOfAnotherNameThatEndsLikeNameIsNotTheName(): void
    {
        $html = AnalyticsInjector::inject($this->page('<meta data-name="yandex-verification" content="' . self::UIN . '">'), AnalyticsConfig::fromSettings([], self::UIN));

        self::assertSame(1, \substr_count($html, '<meta name="yandex-verification"'));
    }

    #[Test]
    public function aCodeThatIsNotACodeIsDropped(): void
    {
        self::assertTrue(AnalyticsConfig::fromSettings([], '"><script>')->isEmpty());
    }
}
