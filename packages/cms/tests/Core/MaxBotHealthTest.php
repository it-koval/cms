<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Bots\BotSettings;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Health\MaxHealthCheck;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Request\Max\MaxApiSender;
use Itk\Cms\Request\MaxNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\TwoFactor\SecretBox;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\MaxApiConfig;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Uses the real container's settings services with in-memory rows, without opening a database or network connection. */
final class MaxBotHealthTest extends KernelTestCase
{
    /** @return array{MaxHealthCheck, BotSettings} */
    private function check(bool $admin = false, bool $unreadable = false, bool $server = true, bool $on = true, bool $routes = true, bool $enabled = true): array
    {
        self::bootKernel();
        $rows = [
            'maxOn' => new Setting('maxOn', '*', $on),
            'maxRoutes' => new Setting('maxRoutes', '*', $routes ? ['*' => '-12345'] : []),
        ];
        $repo = $this->createStub(SettingRepository::class);
        $repo->method('find')->willReturnCallback(static function (array $id) use (&$rows): ?Setting {
            return $rows[$id['key']] ?? null;
        });
        $repo->method('findAll')->willReturnCallback(static function () use (&$rows): array {
            return \array_values($rows);
        });
        self::getContainer()->set(SettingRepository::class, $repo);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (Setting $row) use (&$rows): void {
            $rows[$row->key] = $row;
        });
        $bots = new BotSettings($em, $repo, new SecretBox('max-health-secret'));
        if ($admin || $unreadable) {
            $bots->save(['max' => ['token' => 'admin-health-secret']]);
        }
        if ($unreadable) {
            $bots = new BotSettings($em, $repo, new SecretBox('changed-app-secret'));
        }
        $config = \class_exists(MaxApiConfig::class) ? new MaxApiConfig($server ? 'server-health-secret' : null) : null;
        $sender = new MaxApiSender(null === $config ? null : static fn (): MaxApiClient => new MaxApiClient($config), null === $config ? null : static fn (): MaxApiConfig => $config, new NullLogger(), $bots);
        $settings = self::getContainer()->get(SettingsService::class);
        $schema = self::getContainer()->get(Schema::class);
        $max = new MaxNotifier($schema, $settings, new NullLogger(), $sender, new EventDispatcher(), $enabled);

        return [new MaxHealthCheck($max, $settings, $schema, $bots), $bots];
    }

    #[Test]
    public function healthNamesTheAdminTokenSource(): void
    {
        [$check] = $this->check(admin: true);

        self::assertSame('ok', $check->check()->status);
        self::assertSame(I18n::t('ops.health_max_admin'), $check->check()->message);
    }

    #[Test]
    public function healthNamesTheServerTokenSource(): void
    {
        [$check] = $this->check();

        self::assertSame('ok', $check->check()->status);
        self::assertSame(I18n::t('ops.health_max_server'), $check->check()->message);
    }

    #[Test]
    public function healthWarnsAboutAnUnreadableAdminTokenEvenWithAWorkingServerFallback(): void
    {
        [$check] = $this->check(unreadable: true);

        self::assertSame('warn', $check->check()->status);
        self::assertSame(I18n::t('http.bot_token_unreadable', ['channel' => 'MAX']) . '. ' . I18n::t('ops.health_max_server'), $check->check()->message);
        self::assertStringNotContainsString('admin-health-secret', $check->check()->message);
    }

    #[Test]
    public function anAbsentOptionalTokenStaysCalm(): void
    {
        [$check] = $this->check(server: false);

        self::assertSame('ok', $check->check()->status);
        self::assertSame(I18n::t('ops.health_max_off'), $check->check()->message);
    }

    #[Test]
    public function aConnectedButDisabledBotStillNamesItsTokenSource(): void
    {
        [$check] = $this->check(admin: true, on: false);

        self::assertSame('ok', $check->check()->status);
        self::assertSame(I18n::t('ops.health_max_off_settings') . '. ' . I18n::t('ops.health_max_admin'), $check->check()->message);
    }

    #[Test]
    public function aMissingChatWarningStillNamesItsTokenSource(): void
    {
        [$check] = $this->check(routes: false);

        self::assertSame('warn', $check->check()->status);
        self::assertStringContainsString(I18n::t('ops.health_max_server'), $check->check()->message);
    }

    #[Test]
    public function theConfigSwitchStillNamesTheConnectedTokenSource(): void
    {
        [$check] = $this->check(enabled: false);

        self::assertSame('ok', $check->check()->status);
        self::assertSame(I18n::t('ops.health_max_off_config') . '. ' . I18n::t('ops.health_max_server'), $check->check()->message);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function aSavedAdminTokenWithoutTheClientPackageIsAWarning(): void
    {
        $loader = require \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $loader->setPsr4('MaxMessenger\\Bot\\', []);
        self::assertFalse(\class_exists(MaxApiClient::class));
        [$check] = $this->check(admin: true);

        self::assertSame('warn', $check->check()->status);
        self::assertSame(I18n::t('http.max_problem_client'), $check->check()->message);
    }
}
