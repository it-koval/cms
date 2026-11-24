<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Analytics\AnalyticsAsset;
use Itk\Cms\Analytics\AnalyticsConfig;
use Itk\Cms\Analytics\AnalyticsInjector;
use Itk\Cms\Security\CspNonce;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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

    /** @return iterable<string, array{string}> */
    public static function startModes(): iterable
    {
        yield 'automatic' => ['auto'];
        yield 'manual' => ['manual'];
    }

    #[Test]
    #[DataProvider('startModes')]
    public function verificationWorksWithoutAnyCounterOrLoaderInEitherStartMode(string $start): void
    {
        $config = AnalyticsConfig::fromSettings([
            'yandexVerification' => self::OWNER,
            'googleVerification' => 'google-code-1234',
        ], self::UIN, $start);

        $html = AnalyticsInjector::inject($this->page(), $config);

        self::assertSame([1, 1], [$this->times($html, self::OWNER), $this->times($html, self::UIN)]);
        self::assertSame(1, \substr_count($html, '<meta name="google-site-verification" content="google-code-1234">'));
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<noscript', $html);
    }

    #[Test]
    #[DataProvider('startModes')]
    public function filteringTheSameHtmlTwiceDoesNotMultiplyTheLoaderOrPixel(string $start): void
    {
        $config = AnalyticsConfig::fromSettings([
            'yandexMetrikaId' => '12345678',
            'googleAnalyticsId' => 'G-ABCD1234',
        ], null, $start);
        $once = AnalyticsInjector::inject($this->page(), $config);

        self::assertSame($once, AnalyticsInjector::inject($once, $config));
        self::assertSame(1, \substr_count($once, 'data-itk-analytics'));
        self::assertSame('auto' === $start ? 1 : 0, \substr_count($once, '<noscript'));
    }

    /** @return iterable<string, array{string}> */
    public static function incompleteMetrikaEmbeds(): iterable
    {
        yield 'numeric goal' => ['<script src="https://mc.yandex.ru/metrika/tag.js?id=99999999"></script><script>ym(12345678, "reachGoal", "order");</script>'];
        yield 'single quoted goal' => ['<script src="https://mc.yandex.ru/metrika/tag.js?id=99999999"></script><script>ym(\'12345678\', \'reachGoal\', \'order\');</script>'];
        yield 'double quoted goal' => ['<script src="https://mc.yandex.ru/metrika/tag.js?id=99999999"></script><script>ym("12345678", "reachGoal", "order");</script>'];
        yield 'counter URL with a longer number' => ['<script src="https://mc.yandex.ru/metrika/tag.js?id=123456789"></script>'];
        yield 'counter URL with a suffix' => ['<script src="https://mc.yandex.ru/metrika/tag.js?id=12345678other"></script>'];
    }

    #[Test]
    #[DataProvider('incompleteMetrikaEmbeds')]
    public function aGoalOrAnotherCounterDoesNotSuppressInitializationOrThePixel(string $embed): void
    {
        $html = AnalyticsInjector::inject($this->page($embed), AnalyticsConfig::fromSettings(['yandexMetrikaId' => '12345678']));

        self::assertStringContainsString($embed, $html, 'the site script stays byte-identical');
        self::assertSame('12345678', (new Crawler($html))->filter('script[data-itk-analytics]')->attr('data-metrika'));
        self::assertSame(1, \substr_count($html, 'mc.yandex.ru/watch/12345678'));
    }

    /** @return iterable<string, array{string}> */
    public static function initializedMetrikaEmbeds(): iterable
    {
        yield 'exact URL' => ['<script src="https://mc.yandex.ru/metrika/tag.js?id=12345678&amp;lang=ru"></script>'];
        yield 'numeric init' => ['<script src="https://mc.yandex.ru/metrika/tag.js"></script><script>ym(12345678, "init", {});</script>'];
        yield 'single quoted init' => ['<script src="https://mc.yandex.ru/metrika/tag.js"></script><script>ym(\'12345678\', \'init\', {});</script>'];
        yield 'double quoted init' => ['<script src="https://mc.yandex.ru/metrika/tag.js"></script><script>ym("12345678", "init", {});</script>'];
        yield 'HTML-style script wrapper' => ['<script><!--\nvar src="https://mc.yandex.ru/metrika/tag.js";ym(12345678, "init", {});\n//--></script>'];
        yield 'legacy watch.js' => ['<script src="https://mc.yandex.ru/metrika/watch.js"></script><script>var yaCounter12345678 = new Ya.Metrika({id:12345678});</script>'];
        yield 'init with a site-provided loader' => ['<script src="/site-counters.js"></script><script>ym(12345678, "init", {});</script>'];
        yield 'whitespace before call' => ['<script>ym (12345678, "init", {});</script>'];
        yield 'init without options' => ['<script>ym("12345678", "init");</script>'];
    }

    #[Test]
    #[DataProvider('initializedMetrikaEmbeds')]
    public function anExistingMetrikaInitializationIsPreservedAndNotRepeated(string $embed): void
    {
        $html = AnalyticsInjector::inject($this->page($embed), AnalyticsConfig::fromSettings(['yandexMetrikaId' => '12345678']));

        self::assertStringContainsString($embed, $html);
        self::assertSame('', (new Crawler($html))->filter('script[data-itk-analytics]')->attr('data-metrika'));
        self::assertStringNotContainsString('<noscript', $html);
    }

    #[Test]
    #[DataProvider('startModes')]
    public function commentedTagsAndClosingHeadAreIgnoredWithoutChangingTheComment(string $start): void
    {
        $comment = '<!-- </head><body><script data-itk-analytics></script>'
            . '<meta name="yandex-verification" content="' . self::OWNER . '">'
            . '<meta name="google-site-verification" content="google-code-1234">'
            . '<script src="https://mc.yandex.ru/metrika/tag.js?id=12345678"></script>'
            . '<script src="https://www.googletagmanager.com/gtag/js?id=G-ABCD1234"></script> -->';
        $own = $this->page($comment);
        $config = AnalyticsConfig::fromSettings([
            'yandexMetrikaId' => '12345678', 'googleAnalyticsId' => 'G-ABCD1234',
            'yandexVerification' => self::OWNER, 'googleVerification' => 'google-code-1234',
        ], null, $start);

        $html = AnalyticsInjector::inject($own, $config);

        self::assertStringContainsString($comment, $html, 'comments and all original bytes are preserved');
        $dom = new Crawler($html);
        self::assertCount(1, $dom->filter('head script[data-itk-analytics]'));
        self::assertSame('12345678', $dom->filter('script[data-itk-analytics]')->attr('data-metrika'));
        self::assertSame('G-ABCD1234', $dom->filter('script[data-itk-analytics]')->attr('data-ga'));
        self::assertCount(1, $dom->filter('head meta[name="yandex-verification"]'));
        self::assertCount(1, $dom->filter('head meta[name="google-site-verification"]'));
        self::assertCount('auto' === $start ? 1 : 0, $dom->filter('body > noscript'));
        self::assertSame($html, AnalyticsInjector::inject($html, $config));
    }

    #[Test]
    public function onlyAnActualBodyTagReceivesThePixel(): void
    {
        $own = '<html><head></head><!-- <body> --><body>x</body></html>';

        $html = AnalyticsInjector::inject($own, AnalyticsConfig::fromSettings(['yandexMetrikaId' => '12345678']));

        self::assertStringContainsString("<!-- <body> --><body>\n<noscript>", $html);
    }

    #[Test]
    public function aCommentOpenerInsideAQuotedAttributeDoesNotHideTheRestOfThePage(): void
    {
        $own = $this->page('<meta name="description" content="An example: <!--">');
        $config = AnalyticsConfig::fromSettings(['yandexMetrikaId' => '12345678', 'yandexVerification' => self::OWNER]);

        $html = AnalyticsInjector::inject($own, $config);

        self::assertStringContainsString('<meta name="description" content="An example: <!--">', $html);
        $dom = new Crawler($html);
        self::assertCount(1, $dom->filter('head script[data-itk-analytics]'));
        self::assertCount(1, $dom->filter('head meta[name="yandex-verification"]'));
        self::assertCount(1, $dom->filter('body > noscript'));
    }

    #[Test]
    public function theTrustedNonceIsEscapedAndClientHeadersAreNeverItsSource(): void
    {
        $request = Request::create('/', server: ['HTTP_CSP_NONCE' => 'client-supplied']);
        self::assertNull(CspNonce::fromRequest($request));
        $nonce = 'server"quoted&nonce';
        $request->attributes->set(CspNonce::ATTRIBUTE, $nonce);
        self::assertSame($nonce, CspNonce::fromRequest($request));

        $html = AnalyticsInjector::inject($this->page(), AnalyticsConfig::fromSettings(['googleAnalyticsId' => 'G-ABCD1234']), nonce: CspNonce::fromRequest($request));

        self::assertStringContainsString('nonce="server&quot;quoted&amp;nonce"', $html);
        self::assertSame($nonce, (new Crawler($html))->filter('script[data-itk-analytics]')->attr('nonce'));
    }

    #[Test]
    public function autoKeepsTheLegacyNoscriptPixelWhenTheOptionalClosingHeadTagIsOmitted(): void
    {
        $html = '<!doctype html><html><head><title>t</title><body>x</body></html>';

        $injected = AnalyticsInjector::inject($html, AnalyticsConfig::fromSettings(['yandexMetrikaId' => '12345678']));

        self::assertStringContainsString("<body>\n<noscript>", $injected);
        self::assertSame(1, \substr_count($injected, 'mc.yandex.ru/watch/12345678'));
    }

    #[Test]
    public function theAssetUrlIsEscapedAsAnHtmlAttribute(): void
    {
        $base = '/site"quoted&dir';
        $requests = new RequestStack();
        $requests->push(Request::create('http://localhost' . $base . '/page', server: [
            'SCRIPT_NAME' => $base . '/index.php',
            'SCRIPT_FILENAME' => $base . '/index.php',
            'PHP_SELF' => $base . '/index.php',
        ]));
        $asset = new AnalyticsAsset($requests);

        $html = AnalyticsInjector::inject($this->page(), AnalyticsConfig::fromSettings(['googleAnalyticsId' => 'G-ABCD1234']), $asset);

        self::assertStringContainsString('src="/site&quot;quoted&amp;dir/bundles/itkcms/analytics.js?v=', $html);
        self::assertStringNotContainsString('src="/site"quoted&dir/', $html);
    }
}
