<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\SiteAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** SiteAddress: схема и хост в нижнем регистре, международное имя в punycode, порт только не по умолчанию; путь и запрос адресом сайта не считаются. */
final class SiteAddressTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function urls(): iterable
    {
        yield 'plain' => ['https://example.com', 'https://example.com'];
        yield 'case and a trailing slash' => ['HTTPS://WM.Example.org/', 'https://wm.example.org'];
        yield 'the default port' => ['https://example.com:443', 'https://example.com'];
        yield 'another port' => ['http://example.com:8080', 'http://example.com:8080'];
        yield 'the default port of the other scheme' => ['http://example.com:443', 'http://example.com:443'];
        yield 'cyrillic' => ['https://Пример-Сайта.рф', 'https://xn----8sbarojrwjdmo.xn--p1ai'];
        yield 'no scheme' => ['example.com', null];
        yield 'a path' => ['https://example.com/page', null];
        yield 'a query' => ['https://example.com?a=1', null];
        yield 'user info' => ['https://user@example.com', null];
    }

    #[Test]
    #[DataProvider('urls')]
    public function theOriginIsTheOneAUrlCarries(string $url, ?string $origin): void
    {
        self::assertSame($origin, SiteAddress::fromUrl($url)?->origin());
    }

    #[Test]
    public function theOwnerSeesAnInternationalNameInLetters(): void
    {
        self::assertSame('https://пример-сайта.рф:8443', SiteAddress::fromUrl('https://xn----8sbarojrwjdmo.xn--p1ai:8443')?->unicodeOrigin());
    }

    #[Test]
    public function onlyAHostASearchEngineCanReachIsPublic(): void
    {
        foreach (['http://localhost:8000' => false, 'http://192.168.1.5' => false, 'https://cms.test' => false, 'https://shop.local' => false, 'https://site.example' => false, 'https://shop.example.org' => true, 'https://wm.example.ru' => true, 'https://сайт.рф' => true] as $url => $public) {
            self::assertSame($public, SiteAddress::fromUrl($url)?->isPublic(), $url);
        }
    }
}
