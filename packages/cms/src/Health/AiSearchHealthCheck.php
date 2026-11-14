<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\Content\CrawlerFiles;
use Itk\Cms\Content\RobotsRules;
use Itk\Cms\I18n\I18n;

/**
 * «ИИ-поиск» in «Разработчику → Состояние сайта»: can the crawlers behind AI answers read the site.
 *   any AI crawler of BOTS is closed at «/» in the robots.txt the site serves   warn  names each one
 *   otherwise                                                                    ok    says where to watch the visibility
 * The owner wants the site open to all of them, training crawlers included, so every closed one warns.
 *
 * @internal
 */
final class AiSearchHealthCheck implements HealthCheckInterface
{
    /**
     * Every crawler the site should stay open to, with its kind: SEARCH feeds AI answers (ChatGPT search, Claude, Perplexity, Google AI Overviews,
     * Copilot, Alice AI), FETCHER is a user request in an assistant, TRAINING collects data to train models.
     */
    private const BOTS = [
        ['OAI-SearchBot', 'search'],
        ['Claude-SearchBot', 'search'],
        ['PerplexityBot', 'search'],
        ['Googlebot', 'search'],
        ['Bingbot', 'search'],
        ['YandexBot', 'search'],
        ['YandexAdditional', 'search'],
        ['YandexAdditionalBot', 'search'],
        ['ChatGPT-User', 'fetcher'],
        ['Claude-User', 'fetcher'],
        ['Perplexity-User', 'fetcher'],
        ['GPTBot', 'training'],
        ['ClaudeBot', 'training'],
        ['Google-Extended', 'training'],
    ];

    public function __construct(private readonly CrawlerFiles $files)
    {
    }

    public function name(): string
    {
        return I18n::t('ops.health_ai_search');
    }

    public function check(): HealthResult
    {
        $rules = new RobotsRules($this->files->servedRobots());
        $closed = \array_values(\array_filter(self::BOTS, static fn (array $bot): bool => $rules->isBlocked($bot[0])));
        if ([] === $closed) {
            return HealthResult::ok(I18n::t('ops.health_ai_search_ok'));
        }

        return HealthResult::warn(I18n::t('ops.health_ai_search_blocked', ['bots' => \implode(', ', \array_column($closed, 0))]));
    }
}
