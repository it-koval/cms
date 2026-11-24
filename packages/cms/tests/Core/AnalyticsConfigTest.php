<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Analytics\AnalyticsConfig;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/** Сырые значения настроек превращаются в типизированную конфигурацию; неверное значение даёт проблему, а не вывод. */
final class AnalyticsConfigTest extends TestCase
{
    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $this->kernel->shutdown();
            I18n::useLocale('ru');
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function startModes(): iterable
    {
        yield 'default' => [[], 'auto'];
        yield 'explicit auto' => [['analytics' => ['start' => 'auto']], 'auto'];
        yield 'explicit manual' => [['analytics' => ['start' => 'manual']], 'manual'];
    }

    /** @param array<string, mixed> $options */
    #[Test]
    #[DataProvider('startModes')]
    public function theContainerAcceptsBothStartModes(array $options, string $expected): void
    {
        $this->kernel = new TestKernel('test', true, itk: $options);
        $this->kernel->boot();

        self::assertSame($expected, $this->kernel->getContainer()->getParameter('itk_cms.analytics.start'));
    }

    #[Test]
    public function anUnknownStartModeFailsTheContainerBuild(): void
    {
        $this->kernel = new TestKernel('test', true, itk: ['analytics' => ['start' => 'on-consent']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('itk_cms.analytics.start');
        $this->kernel->boot();
    }

    #[Test]
    public function nothingSetIsEmptyAndHasNoProblems(): void
    {
        foreach ([[], ['yandexMetrikaId' => '', 'googleAnalyticsId' => '  ', 'yandexMetrikaWebvisor' => false], ['yandexMetrikaId' => null, 'yandexVerification' => []]] as $settings) {
            $config = AnalyticsConfig::fromSettings($settings);
            self::assertTrue($config->isEmpty());
            self::assertSame([], $config->problems);
        }
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function metrikaIds(): iterable
    {
        yield 'digits' => ['12345678', '12345678'];
        yield 'trimmed' => ["  1234 \n", '1234'];
        yield 'number from a number field' => [98765432, '98765432'];
        yield 'shortest' => ['1234', '1234'];
        yield 'longest' => ['123456789012', '123456789012'];
        yield 'too short' => ['123', null];
        yield 'too long' => ['1234567890123', null];
        yield 'letters' => ['12a45', null];
        yield 'the whole script pasted' => ['ym(12345678, "init")', null];
        yield 'a float' => [1234.5, null];
    }

    #[Test]
    #[DataProvider('metrikaIds')]
    public function metrikaIdIsFourToTwelveDigits(mixed $raw, ?string $expected): void
    {
        $config = AnalyticsConfig::fromSettings(['yandexMetrikaId' => $raw]);

        self::assertSame($expected, $config->metrikaId);
        self::assertSame(null === $expected ? ['yandexMetrikaId'] : [], $config->problems);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function measurementIds(): iterable
    {
        yield 'as given' => ['G-ABC123XYZ9', 'G-ABC123XYZ9'];
        yield 'lower case is raised' => ['g-abc123xyz9', 'G-ABC123XYZ9'];
        yield 'spaces around' => [' G-ABCD1234 ', 'G-ABCD1234'];
        yield 'shortest' => ['G-ABCD', 'G-ABCD'];
        yield 'longest' => ['G-ABCDEFGHIJKLMNOP', 'G-ABCDEFGHIJKLMNOP'];
        yield 'too long' => ['G-ABCDEFGHIJKLMNOPQ', null];
        yield 'too short' => ['G-ABC', null];
        yield 'universal analytics' => ['UA-12345-1', null];
        yield 'tag manager' => ['GTM-ABCDEF', null];
        yield 'a quote' => ['G-ABCD"1234', null];
        yield 'a tag' => ['G-ABCD</script>', null];
    }

    #[Test]
    #[DataProvider('measurementIds')]
    public function measurementIdIsG4AndUppercased(string $raw, ?string $expected): void
    {
        $config = AnalyticsConfig::fromSettings(['googleAnalyticsId' => $raw]);

        self::assertSame($expected, $config->googleAnalyticsId);
        self::assertSame(null === $expected ? ['googleAnalyticsId'] : [], $config->problems);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function codes(): iterable
    {
        yield 'a bare code' => ['0123456789abcdef', '0123456789abcdef'];
        yield 'dashes and underscores' => ['abcd-EFGH_1234', 'abcd-EFGH_1234'];
        yield 'google style' => ['3k-rUPAa8dN1xKgxAbCdEfGhIjKlMnOpQrStUvWxYz0', '3k-rUPAa8dN1xKgxAbCdEfGhIjKlMnOpQrStUvWxYz0'];
        yield 'a meta tag of Yandex' => ['<meta name="yandex-verification" content="0123456789abcdef" />', '0123456789abcdef'];
        yield 'a meta tag of Google' => ['<meta name="google-site-verification" content="abcdefgh-1234_XYZ" />', 'abcdefgh-1234_XYZ'];
        yield 'single quotes, content first' => ["<meta content='abcdefgh1234' name='yandex-verification'>", 'abcdefgh1234'];
        yield 'a meta tag with spaces around' => ["  <META NAME=\"x\" CONTENT = \"abcdefgh1234\">\n", 'abcdefgh1234'];
        yield 'too short' => ['abc123', null];
        yield 'too long' => [\str_repeat('a', 121), null];
        yield 'longest' => [\str_repeat('a', 120), \str_repeat('a', 120)];
        yield 'a space inside' => ['abcdefgh 1234', null];
        yield 'a quote' => ['abcdefgh"1234', null];
        yield 'markup' => ['abcdefgh"><script>alert(1)</script>', null];
        yield 'a meta tag with a hostile content' => ['<meta name="google-site-verification" content="abcdefgh&quot;><script>">', null];
        yield 'a tag that is not a meta' => ['<script>abcdefgh1234</script>', null];
        yield 'a meta tag without content' => ['<meta name="yandex-verification">', null];
        yield 'an empty content' => ['<meta name="yandex-verification" content="">', null];
    }

    #[Test]
    #[DataProvider('codes')]
    public function verificationCodeIsExtractedFromAMetaTagAndChecked(string $raw, ?string $expected): void
    {
        foreach ([['yandexVerification', 'yandexVerification'], ['googleVerification', 'googleVerification']] as [$key, $property]) {
            $config = AnalyticsConfig::fromSettings([$key => $raw]);

            self::assertSame($expected, $config->{$property}, $key);
            self::assertSame(null === $expected ? [$key] : [], $config->problems, $key);
        }
    }

    #[Test]
    public function webvisorFollowsTheToggleAndNeedsACounter(): void
    {
        self::assertTrue(AnalyticsConfig::fromSettings(['yandexMetrikaId' => '12345678', 'yandexMetrikaWebvisor' => true])->webvisor);
        self::assertTrue(AnalyticsConfig::fromSettings(['yandexMetrikaId' => '12345678', 'yandexMetrikaWebvisor' => '1'])->webvisor);
        self::assertFalse(AnalyticsConfig::fromSettings(['yandexMetrikaId' => '12345678', 'yandexMetrikaWebvisor' => false])->webvisor);
        self::assertFalse(AnalyticsConfig::fromSettings(['yandexMetrikaId' => '12345678'])->webvisor);
        self::assertFalse(AnalyticsConfig::fromSettings(['yandexMetrikaWebvisor' => true])->webvisor, 'no counter, nothing to record');
        self::assertFalse(AnalyticsConfig::fromSettings(['yandexMetrikaId' => 'bad', 'yandexMetrikaWebvisor' => true])->webvisor);
    }

    #[Test]
    public function everyInvalidValueIsAProblemAndTheValidOnesStay(): void
    {
        $config = AnalyticsConfig::fromSettings([
            'yandexMetrikaId' => 'abc',
            'googleAnalyticsId' => 'G-ABCD1234',
            'yandexVerification' => 'x',
            'googleVerification' => '0123456789ab',
        ]);

        self::assertSame(['yandexMetrikaId', 'yandexVerification'], $config->problems);
        self::assertNull($config->metrikaId);
        self::assertNull($config->yandexVerification);
        self::assertSame('G-ABCD1234', $config->googleAnalyticsId);
        self::assertSame('0123456789ab', $config->googleVerification);
        self::assertFalse($config->isEmpty());
    }

    #[Test]
    public function aConfigWithOnlyProblemsIsEmpty(): void
    {
        $config = AnalyticsConfig::fromSettings(['yandexMetrikaId' => 'abc', 'googleAnalyticsId' => 'nope']);

        self::assertTrue($config->isEmpty());
        self::assertSame(['yandexMetrikaId', 'googleAnalyticsId'], $config->problems);
    }
}
