<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Health\AiSearchHealthCheck;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\Tests\Core\Fixture\TemporarySettings;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** «ИИ-поиск» в «Состоянии сайта»: стандартный robots.txt спокойно, любой закрытый бот ИИ (поиск, запросы пользователей, обучение) назван в предупреждении, файл в public/ важнее текста из админки. */
final class AiSearchHealthCheckTest extends KernelTestCase
{
    use TemporarySettings;

    protected function tearDown(): void
    {
        $this->restoreSettings();
        parent::tearDown();
    }

    private function check(string $robots): HealthResult
    {
        self::bootKernel();
        $this->setting('robotsTxt', $robots);

        return self::getContainer()->get(AiSearchHealthCheck::class)->check();
    }

    #[Test]
    public function theStandardRobotsIsOkAndNamesWhereToWatch(): void
    {
        $result = $this->check('');

        self::assertSame(HealthResult::OK, $result->status);
        foreach (['Яндекс Вебмастер', 'Bing Webmaster Tools', 'Google Search Console'] as $place) {
            self::assertStringContainsString($place, $result->message);
        }
    }

    #[Test]
    public function aClosedAnswerBotIsAWarningThatNamesIt(): void
    {
        $result = $this->check("User-agent: OAI-SearchBot\nDisallow: /\n");

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString('OAI-SearchBot', $result->message);
        self::assertStringNotContainsString('Claude-SearchBot', $result->message);
    }

    #[Test]
    public function aClosedYandexAdditionalIsNamedToo(): void
    {
        $result = $this->check("User-agent: *\nDisallow: /admin/\n\nUser-agent: OAI-SearchBot\nUser-agent: YandexAdditional\nDisallow: /\n");

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString('OAI-SearchBot, YandexAdditional', $result->message);
    }

    #[Test]
    public function aClosedSiteListsEveryBotExceptYandexAdditional(): void
    {
        $result = $this->check("User-agent: *\nDisallow: /\n");

        self::assertSame(HealthResult::WARN, $result->status);
        foreach (['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'Googlebot', 'Bingbot', 'YandexBot', 'ChatGPT-User', 'Claude-User', 'Perplexity-User', 'GPTBot', 'ClaudeBot', 'Google-Extended'] as $bot) {
            self::assertStringContainsString($bot, $result->message);
        }
        self::assertStringNotContainsString('YandexAdditional', $result->message);
    }

    #[Test]
    public function aClosedTrainingBotIsAWarningToo(): void
    {
        $result = $this->check("User-agent: GPTBot\nDisallow: /\n");

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString('GPTBot', $result->message);
        self::assertStringNotContainsString('ClaudeBot', $result->message);
    }

    #[Test]
    public function aClosedUserFetcherIsAWarning(): void
    {
        $result = $this->check("User-agent: Claude-User\nDisallow: /\n");

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString('Claude-User', $result->message);
    }

    #[Test]
    public function aStaticFileInPublicWinsOverTheAdminText(): void
    {
        self::bootKernel();
        $file = self::getContainer()->getParameter('kernel.project_dir') . '/public/robots.txt';
        $this->setting('robotsTxt', '');
        $this->assertFileDoesNotExist($file);
        @\mkdir(\dirname($file), 0777, true); // public/ of the test app is not in git: a fresh checkout has none
        \file_put_contents($file, "User-agent: PerplexityBot\nDisallow: /\n");

        try {
            $result = self::getContainer()->get(AiSearchHealthCheck::class)->check();
        } finally {
            \unlink($file);
        }

        self::assertSame(HealthResult::WARN, $result->status);
        self::assertStringContainsString('PerplexityBot', $result->message);
    }
}
