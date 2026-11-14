<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Admin\Theme\AdminTheme;
use Itk\Cms\Security\UserManager;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpKernel\KernelInterface;

/** Название продукта в шапке админки (itk_cms.admin.theme.name): из конфига сайта до окна ITK_ADMIN.theme. */
final class AdminThemeNameTest extends WebTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], $options['itk'] ?? []);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM Itk\Cms\Entity\User u WHERE u.email LIKE :e')->setParameter('e', 'test-%@example.com')->execute();
        unset($_SERVER['ITK_TEST_ADMIN_NAME'], $_ENV['ITK_TEST_ADMIN_NAME']);
        parent::tearDown();
    }

    /** The name as a site sets it from the environment; the shell page says what the admin will show. */
    private function shellWithNameFromEnvironment(string $value): string
    {
        $_SERVER['ITK_TEST_ADMIN_NAME'] = $_ENV['ITK_TEST_ADMIN_NAME'] = $value;
        $client = static::createClient(['itk' => ['admin' => ['theme' => ['name' => '%env(ITK_TEST_ADMIN_NAME)%']]]]);
        $client->loginUser(self::getContainer()->get(UserManager::class)->create('test-theme-name@example.com', 'Название', 'admin', 'password-for-test'), 'itk_admin');
        $client->request('GET', '/admin');

        return (string) $client->getResponse()->getContent();
    }

    #[Test]
    public function theNameFromTheConfigReachesTheShell(): void
    {
        $client = static::createClient(['itk' => ['admin' => ['theme' => ['name' => 'Студия «Ромашка» </script>']]]]);
        $client->loginUser(self::getContainer()->get(UserManager::class)->create('test-theme-name@example.com', 'Название', 'admin', 'password-for-test'), 'itk_admin');

        $client->request('GET', '/admin');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('},"name":"Студия «Ромашка» \u003C/script\u003E"}', $html);
        self::assertStringNotContainsString('Ромашка» </script>', $html, 'the name cannot close the script of the shell');
    }

    #[Test]
    public function withoutANameTheShellSaysNullAndTheAdminKeepsItsOwn(): void
    {
        $client = static::createClient();
        $client->loginUser(self::getContainer()->get(UserManager::class)->create('test-theme-name@example.com', 'Название', 'admin', 'password-for-test'), 'itk_admin');

        $client->request('GET', '/admin');

        self::assertStringContainsString('},"name":null}', (string) $client->getResponse()->getContent());
    }

    /** The tests of a site compare the start of ITK_ADMIN.theme byte by byte (an existing site has such a test), so the new key stands last. */
    #[Test]
    public function theNameComesAfterTheKeysTheSitesPin(): void
    {
        $client = static::createClient(['itk' => ['admin' => ['theme' => ['name' => 'Ромашка']]]]);
        $client->loginUser(self::getContainer()->get(UserManager::class)->create('test-theme-name@example.com', 'Название', 'admin', 'password-for-test'), 'itk_admin');

        $client->request('GET', '/admin');

        self::assertMatchesRegularExpression('/"theme":\{"brand":"#[0-9A-F]{6}","accent":"#[0-9A-F]{6}","radius":\d+,"logo":null,"custom":false,"vars":\{[^}]*\},"name":"Ромашка"\}/u', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function aNameThatIsNotPlainTextStopsTheContainer(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        static::bootKernel(['itk' => ['admin' => ['theme' => ['name' => "Два\nряда"]]]]);
    }

    #[Test]
    public function aNameFromTheEnvironmentIsUsed(): void
    {
        self::assertStringContainsString('},"name":"Из окружения"}', $this->shellWithNameFromEnvironment('Из окружения'));
    }

    #[Test]
    public function anEmptyVariableIsAsIfTheOptionWasNotSet(): void
    {
        self::assertStringContainsString('},"name":null}', $this->shellWithNameFromEnvironment(''));
    }

    #[Test]
    public function aNameFromTheEnvironmentThatIsTooLongIsNotShown(): void
    {
        self::assertStringContainsString('},"name":null}', $this->shellWithNameFromEnvironment(\str_repeat('Я', AdminTheme::MAX_NAME + 1)));
    }
}
