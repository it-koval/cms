<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Security\UserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Админка не ходит за шрифтами к чужим сайтам: шрифт панели и страниц входа лежит в поставляемой сборке и отдаётся с самого сайта. */
final class AdminOwnFontsTest extends WebTestCase
{
    private const BUILD = __DIR__ . '/../../public/admin';
    private const FAMILY = 'Golos Text Variable';

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        foreach (['index', '_auth', 'login', 'login_otp', 'forgot_password', 'reset_password'] as $name) {
            yield $name => [\dirname(__DIR__, 2) . '/templates/admin/' . $name . '.html.twig'];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function authTemplates(): iterable
    {
        foreach (['_auth', 'login', 'login_otp'] as $name) {
            yield $name => [\dirname(__DIR__, 2) . '/templates/admin/' . $name . '.html.twig'];
        }
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $manifest = \json_decode((string) \file_get_contents(self::BUILD . '/.vite/manifest.json'), true);
        self::assertIsArray($manifest);

        return $manifest;
    }

    #[Test]
    #[DataProvider('templates')]
    public function aTemplateOfTheAdminNamesNoForeignFontHost(string $file): void
    {
        self::assertDoesNotMatchRegularExpression('/fonts\.(googleapis|gstatic)\.com/', (string) \file_get_contents($file));
    }

    #[Test]
    #[DataProvider('authTemplates')]
    public function aSignInPageSetsItsTextInTheFontOfThePanelFromTheBuild(string $file): void
    {
        $source = (string) \file_get_contents($file);

        self::assertStringContainsString('"' . self::FAMILY . '", system-ui', $source, 'the system fonts stay as the reserve');
        self::assertStringContainsString('itk_admin_font()', $source, 'the @font-face comes from the build');
    }

    #[Test]
    public function theShippedBuildCarriesTheFontAndAsksNoForeignHost(): void
    {
        $manifest = self::manifest();
        $entry = $manifest['index.html'] ?? null;
        self::assertIsArray($entry);
        $css = '';
        foreach ((array) ($entry['css'] ?? []) as $file) {
            $css .= (string) \file_get_contents(self::BUILD . '/' . $file);
        }
        self::assertStringContainsString('@font-face', $css);
        self::assertStringContainsString(self::FAMILY, $css);
        self::assertStringNotContainsString('fonts.g', $css);
        self::assertStringNotContainsString('fonts.g', (string) \file_get_contents(self::BUILD . '/index.html'));

        $faces = \array_filter(\array_keys($manifest), static fn (string $key): bool => 1 === \preg_match('~golos-text-(cyrillic|latin)-wght-normal\.woff2$~', $key));
        self::assertCount(2, $faces, 'the Cyrillic and the Latin face are listed by the manifest');
        foreach ($faces as $key) {
            self::assertFileExists(self::BUILD . '/' . $manifest[$key]['file']);
            self::assertStringContainsString(\basename((string) $manifest[$key]['file']), $css, 'the style of the panel points at the file');
        }
    }

    #[Test]
    public function theShellAndTheSignInPagesAskNoForeignHostAndServeTheFont(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/login');
        self::assertResponseIsSuccessful();
        $login = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('fonts.g', $login);
        self::assertMatchesRegularExpression('~@font-face\s*\{[^}]*font-family:\s*[\'"]' . self::FAMILY . '[\'"][^}]*src:\s*url\(/bundles/itkcms/admin/assets/golos-text-cyrillic-wght-normal-[\w-]+\.woff2\)~', $login);
        self::assertMatchesRegularExpression('~url\(/bundles/itkcms/admin/assets/golos-text-latin-wght-normal-[\w-]+\.woff2\)~', $login);
        \preg_match_all('~url\(/bundles/itkcms/admin/(assets/[\w.-]+\.woff2)\)~', $login, $files);
        self::assertNotSame([], $files[1]);
        foreach ($files[1] as $file) {
            self::assertFileExists(self::BUILD . '/' . $file, 'the page points at a file that is shipped');
        }

        $client->request('GET', '/admin/forgot-password');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('fonts.g', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('golos-text-cyrillic-wght-normal-', (string) $client->getResponse()->getContent());

        $client->loginUser(self::getContainer()->get(UserManager::class)->create('test-fonts@example.com', 'Шрифты', 'admin', 'password-for-test'), 'itk_admin');
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('fonts.g', (string) $client->getResponse()->getContent());
    }
}
