<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Health\HealthResult;
use Itk\Cms\Health\LegacyTwigFunctionsHealthCheck;
use Itk\Cms\I18n\I18n;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

#[Group('no-database')]
final class LegacyTwigFunctionsHealthCheckTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = \sys_get_temp_dir() . '/itk-legacy-twig-' . \bin2hex(\random_bytes(8));
        \mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    private function template(string $path, string $source): void
    {
        $file = $this->projectDir . '/' . $path;
        (new Filesystem())->mkdir(\dirname($file));
        \file_put_contents($file, $source);
    }

    private function check(): HealthResult
    {
        return (new LegacyTwigFunctionsHealthCheck($this->projectDir))->check();
    }

    #[Test]
    public function missingOrEmptyTemplatesAreHealthy(): void
    {
        self::assertSame(HealthResult::OK, $this->check()->status);
        \mkdir($this->projectDir . '/templates');
        self::assertSame(I18n::t('ops.health_legacy_twig_ok'), $this->check()->message);
    }

    #[Test]
    public function findsAllNineFunctionsWithExactPhysicalLinesAndDeterministicPaths(): void
    {
        $functions = ['menu', 'settings', 'listing', 'media_url', 'media_srcset', 'media_width', 'media_ratio', 'page_url', 'render_sections'];
        $this->template('templates/z.twig', "heading\n{{ render_sections(page) }}");
        $source = "{# first line #}\n";
        $locations = [];
        foreach (\array_slice($functions, 0, -1) as $i => $function) {
            $source .= '{{ ' . $function . '(value) }}' . "\n";
            $locations[] = 'templates/nested/a.html.twig:' . ($i + 2) . ' (' . $function . ')';
        }
        $this->template('templates/nested/a.html.twig', $source);
        $locations[] = 'templates/z.twig:2 (render_sections)';

        $result = $this->check();
        self::assertSame(HealthResult::WARN, $result->status);
        self::assertSame(I18n::t('ops.health_legacy_twig_found', ['count' => 9, 'list' => \implode(', ', $locations)]), $result->message);
    }

    #[Test]
    public function reportsOnlyTenLocationsAndTheExactRemainingCount(): void
    {
        $this->template('templates/many.twig', \str_repeat("{{ menu() }}\n", 13));
        $locations = [];
        for ($line = 1; $line <= 10; ++$line) {
            $locations[] = 'templates/many.twig:' . $line . ' (menu)';
        }

        self::assertSame(I18n::t('ops.health_legacy_twig_found', [
            'count' => 13,
            'list' => \implode(', ', $locations) . I18n::t('ops.doctor_list_more', ['count' => 3]),
        ]), $this->check()->message);
    }

    #[Test]
    public function ignoresStringsRegexesCommentsVerbatimAndPrefixedOrLongerNames(): void
    {
        $this->template('templates/safe.twig', <<<'TWIG'
            Plain text menu() settings() listing()
            {# {{ media_url() }} {{ media_srcset() }} #}
            {% verbatim %}{{ media_width() }} {{ media_ratio() }}{% endverbatim %}
            {{ "page_url() render_sections()" }}
            {{ 'menu(\'x\')' }}
            {{ value matches '/menu\(.*\)|settings\(/' }}
            {{ itk_menu() }} {{ itk_settings() }} {{ itk_listing() }}
            {{ itk_media_url() }} {{ itk_media_srcset() }} {{ itk_media_width() }}
            {{ itk_media_ratio() }} {{ itk_page_url() }} {{ itk_render_sections() }}
            {{ submenu() }} {{ menu_extra() }} {{ custom_menu() }}
            TWIG);

        self::assertSame(HealthResult::OK, $this->check()->status);
    }

    #[Test]
    public function ignoresMethodsFiltersTestsAndMacroDefinitions(): void
    {
        $this->template('templates/safe.twig', <<<'TWIG'
            {{ object.menu() }} {{ object . settings() }} {{ object?.menu() }}
            {{ value | listing() }} {{ value is media_url() }}
            {{ value is not media_srcset() }}
            {% macro media_width() %}Width{% endmacro %}
            {% apply media_ratio() %}Ratio{% endapply %}
            {% filter page_url() %}Url{% endfilter %}
            {% import 'macros.twig' as macros %}{{ macros.render_sections() }}
            TWIG);

        self::assertSame(HealthResult::OK, $this->check()->status);
    }

    #[Test]
    public function distinguishesImportedMacroAliasesFromTheOriginalFunctionNames(): void
    {
        $this->template('templates/macros.twig', <<<'TWIG'
            {% from 'external.twig' import menu as listing, settings %}
            {{ listing() }} {{ settings() }}
            {{ menu() }} {{ media_url() }}
            TWIG);

        self::assertSame(I18n::t('ops.health_legacy_twig_found', [
            'count' => 2,
            'list' => 'templates/macros.twig:3 (menu), templates/macros.twig:3 (media_url)',
        ]), $this->check()->message);
    }

    #[Test]
    public function importsDoNotHideEarlierCallsOrCallsOutsideTheirTwigScope(): void
    {
        $this->template('templates/scopes.twig', <<<'TWIG'
            {{ menu() }}
            {% from 'external.twig' import menu %}
            {{ menu() }}
            {% block outer %}
            {% from 'external.twig' import settings %}
            {{ settings() }}
            {% block nested %}
            {{ settings() }}
            {% endblock %}
            {% endblock %}
            {{ settings() }}
            {% embed 'external.twig' %}
            {{ menu() }}
            {% from 'external.twig' import listing %}
            {{ listing() }}
            {% endembed %}
            {{ listing() }}
            TWIG);

        self::assertSame(I18n::t('ops.health_legacy_twig_found', [
            'count' => 5,
            'list' => 'templates/scopes.twig:1 (menu), templates/scopes.twig:8 (settings), templates/scopes.twig:11 (settings), templates/scopes.twig:13 (menu), templates/scopes.twig:17 (listing)',
        ]), $this->check()->message);
    }

    #[Test]
    public function lexesCustomTagsAndFindsNestedAndMultilineCallsWithoutCompiling(): void
    {
        $this->template('templates/custom.twig', "{% custom_tag menu\n('header') %}\n{{ settings(listing()) }}");

        self::assertSame(I18n::t('ops.health_legacy_twig_found', [
            'count' => 3,
            'list' => 'templates/custom.twig:1 (menu), templates/custom.twig:3 (settings), templates/custom.twig:3 (listing)',
        ]), $this->check()->message);
    }

    #[Test]
    public function findsCallsSeparatedFromParenthesesByInlineComments(): void
    {
        if (\version_compare(Environment::VERSION, '3.15', '<')) {
            self::markTestSkipped('Twig added inline comments in 3.15.');
        }
        $this->template('templates/comment.twig', "{{ menu # note\n ('x') }}\n{{ itk_menu # menu('ignored')\n ('x') }}");

        self::assertSame(I18n::t('ops.health_legacy_twig_found', ['count' => 1, 'list' => 'templates/comment.twig:1 (menu)']), $this->check()->message);
    }

    #[Test]
    public function warnsForMalformedFilesAndContinuesWithoutExposingSource(): void
    {
        $this->template('templates/a-broken.twig', "{{ menu('private-template-sentinel) }}");
        $this->template('templates/z-good.twig', "\n{{ settings() }}");

        $result = $this->check();
        self::assertSame(HealthResult::WARN, $result->status);
        self::assertSame(
            I18n::t('ops.health_legacy_twig_found', ['count' => 1, 'list' => 'templates/z-good.twig:2 (settings)']) . ' '
            . I18n::t('ops.health_legacy_twig_unreadable', ['list' => 'templates/a-broken.twig']),
            $result->message,
        );
        self::assertStringNotContainsString('private-template-sentinel', $result->message);
    }

    #[Test]
    public function boundsTheListOfMalformedFilesAsWell(): void
    {
        $files = [];
        for ($i = 0; $i < 12; ++$i) {
            $path = \sprintf('templates/%02d.twig', $i);
            $this->template($path, '{{ menu(');
            if ($i < 10) {
                $files[] = $path;
            }
        }

        self::assertSame(I18n::t('ops.health_legacy_twig_unreadable', [
            'list' => \implode(', ', $files) . I18n::t('ops.doctor_list_more', ['count' => 2]),
        ]), $this->check()->message);
    }

    #[Test]
    public function scansOnlySiteTwigFilesAndDoesNotFollowSymlinks(): void
    {
        $this->template('templates/ignored.txt', '{{ menu() }}');
        $this->template('vendor/package/templates/ignored.twig', '{{ menu() }}');
        $this->template('outside/ignored.twig', '{{ menu() }}');
        $this->template('templates/safe.twig', '{{ itk_menu() }}');
        \symlink($this->projectDir . '/outside/ignored.twig', $this->projectDir . '/templates/link.twig');
        \symlink($this->projectDir . '/outside', $this->projectDir . '/templates/directory');

        self::assertSame(HealthResult::OK, $this->check()->status);
    }
}
