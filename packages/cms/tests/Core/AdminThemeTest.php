<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Admin\Theme\AdminTheme;
use Itk\Cms\Admin\Theme\Color;
use Itk\Cms\Admin\Theme\Palette;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Оформление админки: цвета в OKLCH, палитра 50–950, контраст AA, настройка adminTheme, CSS-переменные в оболочке и API. */
final class AdminThemeTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $csrf = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key = :k')->setParameter('k', AdminTheme::KEY)->execute();
        $em->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    private function theme(): AdminTheme
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return new AdminTheme($em, $em->getRepository(Setting::class), ['brand' => '#0B5D80', 'accent' => '#C8217A', 'radius' => 6, 'logo' => null]);
    }

    private function signIn(string $role = 'admin'): void
    {
        $user = self::getContainer()->get(UserManager::class)->create('test-theme-' . $role . '@example.com', 'Тест оформления', $role, 'password-for-test');
        $this->client->loginUser($user, 'itk_admin');
        $this->client->request('GET', '/admin');
        \preg_match('/"csrf":"([a-f0-9]+)"/', (string) $this->client->getResponse()->getContent(), $m);
        $this->csrf = $m[1] ?? '';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, array $body = [], int $status = 200): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrf];
        $this->client->request($method, '/admin/api/' . $path, [], [], $server, [] === $body ? null : \json_encode($body));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /* ---------- colour maths ---------- */

    #[Test]
    public function coloursAreReadAndWrittenBackThroughOklch(): void
    {
        self::assertSame('#0B5D80', Color::normalize('0b5d80'));
        self::assertSame('#AABBCC', Color::normalize('#abc'));
        self::assertNull(Color::normalize('#12345'));
        self::assertNull(Color::normalize('red'));
        foreach (['#0B5D80', '#C8217A', '#FFD400', '#FFFFFF', '#000000', '#7A7A7A', '#1B5E20'] as $hex) {
            [$l, $c, $h] = Color::toOklch($hex);
            self::assertSame($hex, Color::fromOklch($l, $c, $h), $hex);
        }
        [$l, $c, $h] = Color::toOklch('#0B5D80');
        self::assertEqualsWithDelta(0.451, $l, 0.005);
        self::assertEqualsWithDelta(0.091, $c, 0.005);
        self::assertEqualsWithDelta(234.0, $h, 2.0);
    }

    #[Test]
    public function contrastFollowsWcag(): void
    {
        self::assertEqualsWithDelta(21.0, Color::contrast('#000000', '#FFFFFF'), 0.001);
        self::assertEqualsWithDelta(1.0, Color::contrast('#0B5D80', '#0B5D80'), 0.001);
        self::assertGreaterThan(7.0, Color::contrast('#0B5D80', '#FFFFFF'));
        self::assertEqualsWithDelta(Color::contrast('#0B5D80', '#FFFFFF'), Color::contrast('#FFFFFF', '#0B5D80'), 1e-9);
    }

    #[Test]
    public function aChromaOutsideTheScreenIsLoweredNotClippedToAnotherHue(): void
    {
        $hex = Color::fromOklch(0.62, 0.4, 150.0);
        [, $c, $h] = Color::toOklch($hex);
        self::assertLessThan(0.4, $c);
        self::assertEqualsWithDelta(150.0, $h, 6.0);
    }

    /* ---------- the palette ---------- */

    #[Test]
    public function theLadderHasElevenStepsFromLightToDarkAndContainsTheBrand(): void
    {
        foreach (['#0B5D80', '#C8217A', '#FFD400', '#1B5E20', '#6A1B9A'] as $hex) {
            $ladder = Palette::ladder($hex);
            self::assertSame(Palette::STEPS, \array_keys($ladder));
            self::assertContains($hex, $ladder);
            $light = \array_values(\array_map(static fn (string $c): float => Color::toOklch($c)[0], $ladder));
            $sorted = $light;
            \rsort($sorted);
            // lighter to darker; only the anchored brand step may sit slightly off the ladder
            self::assertEqualsWithDelta($sorted, $light, 0.1, $hex);
            self::assertGreaterThan(Color::toOklch($ladder[950])[0], Color::toOklch($ladder[50])[0]);
        }
        self::assertSame('#0B5D80', Palette::ladder('#0B5D80')[700]);
        self::assertNotSame('#0B5D80', Palette::ladder('#0B5D80', false)[700]);
    }

    #[Test]
    public function inkIsWhiteWhereItReadsElseTheDarkInk(): void
    {
        self::assertSame(Palette::WHITE, Palette::ink('#0B5D80'));
        self::assertSame(Palette::DARK, Palette::ink('#FFD400'));
        self::assertGreaterThanOrEqual(4.5, Palette::bestContrast('#FFD400'));
        // a mid grey: neither white nor the dark ink reaches AA on it
        self::assertLessThan(4.5, Palette::bestContrast('#7C7C7C'));
    }

    #[Test]
    public function colourAsTextIsDarkenedUntilItReadsOnWhite(): void
    {
        $text = Palette::textOn('#FFD400', [Palette::WHITE]);
        self::assertNotSame('#FFD400', $text);
        self::assertGreaterThanOrEqual(4.5, Color::contrast($text, Palette::WHITE));
        self::assertSame('#0B5D80', Palette::textOn('#0B5D80', [Palette::WHITE]));
    }

    /* ---------- the theme ---------- */

    /** @return iterable<string, array{string, string}> */
    public static function looks(): iterable
    {
        yield 'standard' => ['#0B5D80', '#C8217A'];
        yield 'green and orange' => ['#1B5E20', '#E65100'];
        yield 'yellow' => ['#FFD400', '#6A1B9A'];
        yield 'light blue' => ['#7FC8F8', '#D81B60'];
        yield 'near black' => ['#14181C', '#F9A825'];
        yield 'red' => ['#C62828', '#00796B'];
        yield 'purple' => ['#4527A0', '#FFB300'];
    }

    #[Test]
    #[DataProvider('looks')]
    public function everyColourThatMayBeSavedGetsReadableText(string $brand, string $accent): void
    {
        $vars = $this->theme()->vars(['brand' => $brand, 'accent' => $accent, 'radius' => 6]);
        foreach (['brand' => 'cy', 'accent' => 'mag'] as $name => $short) {
            $colour = 'brand' === $name ? $brand : $accent;
            self::assertSame($colour, $vars['--' . $name]);
            self::assertSame($colour, $vars['--' . $short]);
            self::assertGreaterThanOrEqual(4.5, Color::contrast($colour, $vars['--' . $name . '-ink']), $name . ' ink');
            self::assertGreaterThanOrEqual(4.5, Color::contrast($vars['--' . $name . '-text'], '#FFFFFF'), $name . ' text on white');
            self::assertGreaterThanOrEqual(4.5, Color::contrast($vars['--' . $name . '-text'], $vars['--' . $short . '-50']), $name . ' text on its tint');
            self::assertGreaterThanOrEqual(4.5, Color::contrast($vars['--' . $name . '-text-d'], $vars['--' . $short . '-50']), $name . ' dark text on its tint');
        }
        self::assertGreaterThanOrEqual(4.5, Color::contrast($vars['--rail-ink'], $brand), 'rail text');
        // the second rail text of the standard look is the admin's own hand-tuned #A9CFE1 (4.39:1), kept as designed
        self::assertGreaterThanOrEqual('#0B5D80' === $brand ? 4.3 : 4.5, Color::contrast($vars['--rail-ink-2'], $brand), 'rail text, second');
        self::assertGreaterThanOrEqual(7.0, Color::contrast($vars['--cy-dd'], $vars['--cy-50']), 'darkest brand on its tint');
        self::assertGreaterThanOrEqual(4.5, Color::contrast($vars['--cy-d'], $vars['--brand-ink']), 'hover surface');
        foreach (Palette::STEPS as $step) {
            self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $vars['--brand-' . $step]);
            self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $vars['--accent-' . $step]);
        }
    }

    #[Test]
    public function theStandardLookKeepsTheHandTunedColoursOfTheAdmin(): void
    {
        $vars = $this->theme()->vars(['brand' => '#0B5D80', 'accent' => '#C8217A', 'radius' => 6]);
        self::assertSame('#084A66', $vars['--cy-d']);
        self::assertSame('#063A51', $vars['--cy-dd']);
        self::assertSame('#E8F3F8', $vars['--cy-50']);
        self::assertSame('#CFE6F0', $vars['--cy-100']);
        self::assertSame('#9CCAE0', $vars['--cy-200']);
        self::assertSame('#D2E8F2', $vars['--rail-ink']);
        self::assertSame('#A3185F', $vars['--mag-d']);
        self::assertSame('#FFFFFF', $vars['--brand-ink']);
        self::assertSame('6px', $vars['--r']);
        self::assertSame('10px', $vars['--r-lg']);
    }

    #[Test]
    public function cssHoldsOnlyCheckedValues(): void
    {
        $theme = $this->theme();
        $css = AdminTheme::css($theme->vars(['brand' => '#FFD400', 'accent' => '#6A1B9A', 'radius' => 0]));
        self::assertStringStartsWith(':root{--cy:#FFD400;', $css);
        self::assertStringContainsString('--brand-ink:#14181C', $css);
        self::assertStringContainsString('--r:0px', $css);
        self::assertSame(1, \preg_match('/^:root\{(--[a-z0-9-]+:[#a-zA-Z0-9(),.%]+;?)+\}$/', $css), $css);
    }

    #[Test]
    public function aLookIsCheckedBeforeItIsSaved(): void
    {
        $theme = $this->theme();
        self::assertSame(['brand' => '#1B5E20', 'accent' => '#C8217A', 'radius' => 8], $theme->sanitize(['brand' => '1b5e20', 'radius' => '8']));
        foreach ([['brand' => 'синий'], ['accent' => '#12'], ['radius' => 40], ['radius' => -1], ['radius' => 'много'], ['brand' => '#7C7C7C']] as $bad) {
            try {
                $theme->sanitize($bad);
                self::fail('Expected a refusal for ' . \json_encode($bad));
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
                self::assertNotEmpty($e->details());
            }
        }
        // the preview only needs colours to be colours: a grey is drawn, with a warning in checks
        self::assertSame('#7C7C7C', $theme->sanitize(['brand' => '#7C7C7C'], false)['brand']);
    }

    #[Test]
    public function theLookIsStoredAsTheSettingAdminThemeAndAStandardLookRemovesIt(): void
    {
        $theme = $this->theme();
        self::assertFalse($theme->isCustom());
        $saved = $theme->save(['brand' => '#1B5E20', 'radius' => 10]);
        self::assertTrue($saved['custom']);
        self::assertSame('#1B5E20', $saved['theme']['brand']);
        self::assertSame('#C8217A', $saved['theme']['accent']);
        $row = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Setting::class)->find(['key' => 'adminTheme', 'locale' => '*']);
        self::assertSame(['brand' => '#1B5E20', 'accent' => '#C8217A', 'radius' => 10], $row->value);
        self::assertSame('#1B5E20', $this->theme()->effective()['brand']);

        $back = $theme->save(['brand' => '#0B5D80', 'radius' => 6]);
        self::assertFalse($back['custom']);
        self::assertFalse($this->theme()->isCustom());

        $theme->save(['accent' => '#00796B']);
        $theme->reset();
        self::assertFalse($this->theme()->isCustom());
        self::assertSame('#C8217A', $this->theme()->effective()['accent']);
    }

    #[Test]
    public function aDamagedStoredValueFallsBackToTheDeveloperLook(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Setting(AdminTheme::KEY, Setting::ALL, ['brand' => 'javascript:alert(1)', 'accent' => '</style>', 'radius' => 'x']));
        $em->flush();
        $look = $this->theme()->effective();
        self::assertSame(['brand' => '#0B5D80', 'accent' => '#C8217A', 'radius' => 6], $look);
        self::assertStringNotContainsString('<', AdminTheme::css($this->theme()->vars($look)));
    }

    #[Test]
    public function theDeveloperConfigSetsTheStandardLookAndOnlyASafeLogoIsShown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $own = new AdminTheme($em, $em->getRepository(Setting::class), ['brand' => '#1B5E20', 'accent' => '#E65100', 'radius' => 3, 'logo' => '/build/logo.svg']);
        self::assertSame(['brand' => '#1B5E20', 'accent' => '#E65100', 'radius' => 3], $own->effective());
        self::assertSame('/build/logo.svg', $own->logo());
        $bad = new AdminTheme($em, $em->getRepository(Setting::class), ['logo' => 'javascript:alert(1)']);
        self::assertNull($bad->logo());
        self::assertNull((new AdminTheme($em, $em->getRepository(Setting::class), ['logo' => '//evil.example/x.svg']))->logo());
        self::assertSame(['brand' => '#0B5D80', 'accent' => '#C8217A', 'radius' => 6], $bad->defaults());
    }

    #[Test]
    public function theDeveloperMayNameTheProductInTheHeaderAndOnlyAPlainNameIsShown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $named = static fn (mixed $name): ?string => (new AdminTheme($em, $em->getRepository(Setting::class), ['name' => $name]))->name();
        self::assertSame('Студия Ромашка', $named('  Студия Ромашка '));
        self::assertNull($named(null));
        self::assertNull($named(''));
        self::assertNull($named("Два\nряда"));
        self::assertNull($named(\str_repeat('я', 41)));
        self::assertSame(\str_repeat('я', 40), $named(\str_repeat('я', 40)));
        self::assertNull((new AdminTheme($em, $em->getRepository(Setting::class)))->name());
    }

    #[Test]
    public function thePreviewFrameGetsTheColoursOfTheOverlay(): void
    {
        $theme = $this->theme();
        self::assertSame(':root{--itk-brand:#0B5D80;--itk-brand-50:#E8F3F8;--itk-brand-text:#0B5D80;--itk-brand-text-d:#084A66;--itk-accent:#C8217A}', $theme->previewCss());
        $theme->save(['brand' => '#1B5E20', 'accent' => '#E65100']);
        $css = $this->theme()->previewCss();
        self::assertStringContainsString('--itk-brand:#1B5E20;', $css);
        self::assertStringContainsString('--itk-accent:#E65100}', $css);
    }

    /* ---------- the API and the shell ---------- */

    #[Test]
    public function theApiPreviewsSavesAndResetsTheLook(): void
    {
        $this->signIn();
        $now = $this->api('GET', 'admin-theme');
        self::assertFalse($now['custom']);
        self::assertSame('#0B5D80', $now['theme']['brand']);
        self::assertSame('#0B5D80', $now['vars']['--cy']);
        self::assertSame(Palette::STEPS, \array_map('intval', \array_keys($now['ladder']['brand'])));
        self::assertTrue($now['checks']['brand']['ok']);
        self::assertSame('AAA', $now['checks']['brand']['level']);

        $preview = $this->api('POST', 'admin-theme/preview', ['brand' => '#FFD400']);
        self::assertSame('#FFD400', $preview['vars']['--cy']);
        self::assertSame('#14181C', $preview['vars']['--brand-ink']);
        self::assertTrue($preview['custom']);
        self::assertFalse($this->api('GET', 'admin-theme')['custom'], 'a preview saves nothing');

        $grey = $this->api('POST', 'admin-theme/preview', ['brand' => '#7C7C7C']);
        self::assertFalse($grey['checks']['brand']['ok']);
        self::assertSame('fail', $grey['checks']['brand']['level']);
        $this->api('POST', 'admin-theme/preview', ['brand' => 'нет'], 422);

        $refused = $this->api('PUT', 'admin-theme', ['brand' => '#7C7C7C'], 422);
        self::assertArrayHasKey('brand', $refused['details']);
        self::assertFalse($this->api('GET', 'admin-theme')['custom']);

        $saved = $this->api('PUT', 'admin-theme', ['brand' => '#1B5E20', 'accent' => '#E65100', 'radius' => 12]);
        self::assertTrue($saved['custom']);
        self::assertSame('12px', $saved['vars']['--r']);
        self::assertSame('16px', $saved['vars']['--r-lg']);
        self::assertSame('#1B5E20', $this->api('GET', 'admin-theme')['theme']['brand']);

        $reset = $this->api('DELETE', 'admin-theme');
        self::assertFalse($reset['custom']);
        self::assertSame('#0B5D80', $reset['theme']['brand']);
    }

    #[Test]
    public function theShellCarriesTheLookBeforeTheAdminStarts(): void
    {
        $this->signIn();
        $this->api('PUT', 'admin-theme', ['brand' => '#1B5E20']);
        $this->client->request('GET', '/admin');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<meta name="theme-color" content="#1B5E20">', $html);
        self::assertMatchesRegularExpression('/<style id="itk-theme">:root\{--cy:#1B5E20;/', $html);
        self::assertStringContainsString('fill=\'%231B5E20\'', $html);
        self::assertMatchesRegularExpression('/"theme":\{"brand":"#1B5E20","accent":"#C8217A","radius":6,"logo":null,"custom":true,"vars":\{/', $html);
        // the style is part of the page itself, not something the bundle fetches later
        if (\str_contains($html, 'rel="stylesheet" href="/bundles')) {
            self::assertGreaterThan(\strrpos($html, 'rel="stylesheet" href="/bundles'), \strpos($html, 'id="itk-theme"'), 'after the stylesheets of the bundle, so it wins');
        }
    }

    #[Test]
    public function theSignInPageWearsTheColoursToo(): void
    {
        $this->signIn();
        $this->api('PUT', 'admin-theme', ['brand' => '#1B5E20']);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/admin/login');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<meta name="theme-color" content="#1B5E20">', $html);
        self::assertStringContainsString(':root{--cy:#1B5E20;', $html);
    }

    #[Test]
    public function aRoleWithoutTheRightCannotReadOrChangeTheLook(): void
    {
        $this->signIn('editor');
        $this->api('GET', 'admin-theme', [], 403);
        $this->api('POST', 'admin-theme/preview', ['brand' => '#1B5E20'], 403);
        $this->api('PUT', 'admin-theme', ['brand' => '#1B5E20'], 403);
        $this->api('DELETE', 'admin-theme', [], 403);
        self::assertFalse($this->theme()->isCustom());
        // but the editor still gets the colours in the shell
        $this->client->request('GET', '/admin');
        self::assertStringContainsString('id="itk-theme"', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function theRightIsAnAreaOfTheMatrixAndTheAdministratorHasIt(): void
    {
        $this->signIn();
        $schema = $this->api('GET', 'schema');
        $areas = \array_column($schema['permissionAreas'], null, 'key');
        self::assertArrayHasKey('set:theme', $areas);
        self::assertSame('settings', $areas['set:theme']['group']);
        self::assertSame(['view', 'edit'], $areas['set:theme']['actions']);
        $me = $this->api('GET', 'me');
        self::assertSame(['view', 'edit'], $me['perms']['set:theme']);
    }
}
