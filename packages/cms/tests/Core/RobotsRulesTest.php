<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\RobotsRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** robots.txt по RFC 9309: группа бота, самое длинное совпадение, Allow при равенстве, шаблоны * и $, особые правила Яндекса. */
final class RobotsRulesTest extends TestCase
{
    #[Test]
    public function anEmptyFileAllowsEverything(): void
    {
        self::assertFalse((new RobotsRules(''))->isBlocked('GPTBot'));
        self::assertFalse((new RobotsRules("\n# only a comment\n"))->isBlocked('YandexBot', '/any'));
    }

    #[Test]
    public function anEmptyDisallowAllowsEverything(): void
    {
        self::assertFalse((new RobotsRules("User-agent: *\nDisallow:\n"))->isBlocked('Googlebot'));
    }

    #[Test]
    public function theGroupThatNamesTheBotBeatsTheStarGroup(): void
    {
        $rules = new RobotsRules("User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nDisallow:\n");

        self::assertFalse($rules->isBlocked('Googlebot'));
        self::assertTrue($rules->isBlocked('Bingbot'));
    }

    #[Test]
    public function aStarGroupAppliesToABotWithoutItsOwnGroup(): void
    {
        self::assertTrue((new RobotsRules("User-agent: *\nDisallow: /\n"))->isBlocked('PerplexityBot'));
    }

    #[Test]
    public function allowInsideABlockedGroupOpensThePath(): void
    {
        $rules = new RobotsRules("User-agent: *\nDisallow: /\nAllow: /\n");

        self::assertFalse($rules->isBlocked('Googlebot'));
    }

    #[Test]
    public function theLongestMatchWins(): void
    {
        $rules = new RobotsRules("User-agent: *\nDisallow: /\nAllow: /public/\nDisallow: /public/secret/\n");

        self::assertTrue($rules->isBlocked('Googlebot', '/'));
        self::assertFalse($rules->isBlocked('Googlebot', '/public/page'));
        self::assertTrue($rules->isBlocked('Googlebot', '/public/secret/x'));
    }

    #[Test]
    public function aTieGoesToAllow(): void
    {
        self::assertFalse((new RobotsRules("User-agent: *\nDisallow: /page\nAllow: /page\n"))->isBlocked('Googlebot', '/page'));
        self::assertFalse((new RobotsRules("User-agent: *\nAllow: /page\nDisallow: /page\n"))->isBlocked('Googlebot', '/page'));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function patterns(): iterable
    {
        yield 'prefix' => ['/private', '/private/a', true];
        yield 'prefix misses' => ['/private', '/public', false];
        yield 'end anchor matches' => ['/*.pdf$', '/a/b.pdf', true];
        yield 'end anchor does not match a suffix' => ['/*.pdf$', '/a/b.pdf?x=1', false];
        yield 'star in the middle' => ['/a*/c', '/abc/c', true];
        yield 'star does not need a match' => ['/a*b', '/ab', true];
        yield 'dollar alone' => ['/$', '/', true];
        yield 'dollar alone misses a deeper path' => ['/$', '/x', false];
    }

    #[Test]
    #[DataProvider('patterns')]
    public function patternsUseStarAndDollar(string $pattern, string $path, bool $blocked): void
    {
        self::assertSame($blocked, (new RobotsRules("User-agent: *\nDisallow: {$pattern}\n"))->isBlocked('Googlebot', $path));
    }

    #[Test]
    public function tokensAndFieldNamesAreCaseInsensitive(): void
    {
        $rules = new RobotsRules("USER-AGENT: oai-searchbot\nDISALLOW: /\n");

        self::assertTrue($rules->isBlocked('OAI-SearchBot'));
        self::assertTrue($rules->isBlocked('oai-searchbot'));
        self::assertFalse($rules->isBlocked('PerplexityBot'));
    }

    #[Test]
    public function severalUserAgentLinesShareOneGroup(): void
    {
        $rules = new RobotsRules("User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /\n");

        self::assertTrue($rules->isBlocked('GPTBot'));
        self::assertTrue($rules->isBlocked('ClaudeBot'));
        self::assertFalse($rules->isBlocked('Googlebot'));
    }

    #[Test]
    public function commentsBlankLinesAndOddLineEndingsAreIgnored(): void
    {
        $rules = new RobotsRules("# head\r\n\r\nUser-agent: Bingbot # the bot\r\n\r\n# between\r\nDisallow: / # all\r\n");

        self::assertTrue($rules->isBlocked('Bingbot'));
    }

    #[Test]
    public function aBomAndUnknownFieldsAreIgnored(): void
    {
        $rules = new RobotsRules("\xEF\xBB\xBFUser-agent: *\nSitemap: https://example.org/sitemap.xml\nCrawl-delay: 5\nDisallow: /\n");

        self::assertTrue($rules->isBlocked('Googlebot'));
    }

    #[Test]
    public function aRuleWithoutAGroupIsIgnored(): void
    {
        self::assertFalse((new RobotsRules("Disallow: /\n"))->isBlocked('Googlebot'));
    }

    #[Test]
    public function yandexAdditionalIgnoresTheStarGroup(): void
    {
        $rules = new RobotsRules("User-agent: *\nDisallow: /\n");

        self::assertTrue($rules->isBlocked('YandexBot'));
        self::assertFalse($rules->isBlocked('YandexAdditional'));
        self::assertFalse($rules->isBlocked('YandexAdditionalBot'));
    }

    #[Test]
    public function yandexAdditionalHonoursItsOwnGroup(): void
    {
        $rules = new RobotsRules("User-agent: YandexAdditional\nDisallow: /\n");

        self::assertTrue($rules->isBlocked('YandexAdditional'));
        self::assertFalse($rules->isBlocked('YandexAdditionalBot'));
        self::assertFalse($rules->isBlocked('YandexBot'));
    }

    #[Test]
    public function aYandexGroupAppliesToEveryYandexBotAndBeatsTheStarGroup(): void
    {
        $rules = new RobotsRules("User-agent: *\nDisallow: /\n\nUser-agent: Yandex\nDisallow:\n");
        self::assertFalse($rules->isBlocked('YandexBot'));
        self::assertFalse($rules->isBlocked('YandexAdditional'));
        self::assertTrue($rules->isBlocked('Googlebot'));

        $closed = new RobotsRules("User-agent: Yandex\nDisallow: /\n");
        self::assertTrue($closed->isBlocked('YandexBot'));
        self::assertTrue($closed->isBlocked('YandexAdditionalBot'));
        self::assertFalse($closed->isBlocked('Googlebot'));
    }

    #[Test]
    public function aSpecificYandexGroupBeatsTheYandexGroup(): void
    {
        $rules = new RobotsRules("User-agent: Yandex\nDisallow: /\n\nUser-agent: YandexBot\nDisallow:\n");

        self::assertFalse($rules->isBlocked('YandexBot'));
        self::assertTrue($rules->isBlocked('YandexAdditional'));
    }

    #[Test]
    public function groupsWithTheSameTokenAreMerged(): void
    {
        $rules = new RobotsRules("User-agent: Googlebot\nDisallow: /a\n\nUser-agent: Googlebot\nDisallow: /b\n");

        self::assertTrue($rules->isBlocked('Googlebot', '/a'));
        self::assertTrue($rules->isBlocked('Googlebot', '/b'));
    }

    #[Test]
    public function aUserAgentValueIsCutToItsProductToken(): void
    {
        $rules = new RobotsRules("User-agent: Googlebot/2.1\nDisallow: /\n");

        self::assertTrue($rules->isBlocked('Googlebot'));
        self::assertFalse($rules->isBlocked('Bingbot'));
    }

    #[Test]
    public function aVersionedGroupBeatsTheStarGroup(): void
    {
        $rules = new RobotsRules("User-agent: *\nDisallow: /\n\nUser-agent: Googlebot/2.1\nDisallow:\n");

        self::assertFalse($rules->isBlocked('Googlebot'));
        self::assertTrue($rules->isBlocked('Bingbot'));
    }

    #[Test]
    public function aStarFollowedByWhiteSpaceIsStillTheStarGroup(): void
    {
        $rules = new RobotsRules("User-agent: * foo\nDisallow: /\n");

        self::assertTrue($rules->isBlocked('Googlebot'));
        self::assertFalse((new RobotsRules("User-agent: *foo\nDisallow: /\n"))->isBlocked('Googlebot'));
    }

    #[Test]
    public function aSitemapLineBetweenUserAgentLinesKeepsOneGroup(): void
    {
        $rules = new RobotsRules("User-agent: GPTBot\nSitemap: https://example.org/sitemap.xml\nUser-agent: ClaudeBot\nDisallow: /\n");

        self::assertTrue($rules->isBlocked('GPTBot'));
        self::assertTrue($rules->isBlocked('ClaudeBot'));
    }

    #[Test]
    public function aValueWithoutATokenNamesNobodyButStillFormsTheGroup(): void
    {
        $rules = new RobotsRules("User-agent: 123\nUser-agent: GPTBot\nDisallow: /\n");

        self::assertTrue($rules->isBlocked('GPTBot'));
        self::assertFalse($rules->isBlocked('Googlebot'));
    }
}
