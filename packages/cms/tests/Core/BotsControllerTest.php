<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Bots\BotSettings;
use Itk\Cms\Controller\Admin\BotsController;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Entity\User;
use Itk\Cms\Repository\RoleRepository;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Request\Max\MaxApiSender;
use Itk\Cms\Request\TelegramConnection;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\RoleResolver;
use Itk\Cms\Security\TwoFactor\SecretBox;
use MaxMessenger\Bot\MaxApiClient;
use MaxMessenger\Bot\MaxApiConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BotsControllerTest extends TestCase
{
    private const TOKEN = '123456789:abcdefghijklmnopqrstuvwxyz_123456789';
    /** @var array<string, Setting> */
    private array $rows = [];
    private Role $role;
    private BotsController $controller;

    protected function setUp(): void
    {
        $repo = $this->createStub(SettingRepository::class);
        $repo->method('find')->willReturnCallback(fn (array $id): ?Setting => $this->rows[$id['key']] ?? null);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (Setting $row): void { $this->rows[$row->key] = $row; });
        $bots = new BotSettings($em, $repo, new SecretBox('controller-test-secret'));
        $this->role = new Role('admin', 'Administrator');
        $this->role->builtin = true;
        $roles = $this->createStub(RoleRepository::class);
        $roles->method('find')->willReturnCallback(fn (): Role => $this->role);
        $schema = new Schema('ru', [], [], [], ['top' => [], 'bottom' => []], [], [], [], [], []);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User('bots@example.com', 'Bots', 'admin'));
        $access = new Access($security, new RoleResolver($schema, $roles));
        $http = new MockHttpClient(static function (): never { self::fail('Saving must not make a network request'); });
        $server = new TelegramTransport('123456789:server-token', client: $http);
        $telegram = new TelegramConnection(static fn (): TelegramTransport => $server, new NullLogger(), $bots, $http);
        $config = new MaxApiConfig('server-max-token');
        $max = new MaxApiSender(static fn (): MaxApiClient => new MaxApiClient($config), static fn (): MaxApiConfig => $config, new NullLogger(), $bots);
        $this->controller = new BotsController($bots, $telegram, $max, $access);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function save(array $body): array
    {
        $request = Request::create('/admin/api/settings/bots', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: \json_encode($body));

        return \json_decode((string) $this->controller->save($request)->getContent(), true);
    }

    #[Test]
    public function responsesNeverContainTokensAndClearingRestoresServerSources(): void
    {
        $saved = $this->save(['telegram' => ['token' => self::TOKEN], 'max' => ['token' => 'private-max-token']]);
        $read = \json_decode((string) $this->controller->get()->getContent(), true);
        foreach ([$saved, $read] as $answer) {
            foreach (['telegram', 'max'] as $channel) {
                self::assertSame(['tokenSet' => true, 'source' => 'admin', 'ready' => true], $answer[$channel]);
            }
            self::assertStringNotContainsString(self::TOKEN, (string) \json_encode($answer));
            self::assertStringNotContainsString('private-max-token', (string) \json_encode($answer));
            self::assertStringNotContainsString('v1.', (string) \json_encode($answer));
        }
        self::assertSame($saved, $this->save(['telegram' => ['token' => ''], 'max' => ['token' => '']]));
        $cleared = $this->save(['telegram' => ['clear' => true], 'max' => ['clear' => true]]);
        foreach (['telegram', 'max'] as $channel) {
            self::assertSame(['tokenSet' => true, 'source' => 'server', 'ready' => true], $cleared[$channel]);
        }
    }

    #[Test]
    public function customRolesDoNotGainTheAreaAndViewOnlyCannotSave(): void
    {
        $this->role->builtin = false;
        $this->role->perms = ['set:mail' => ['view', 'edit'], 'set:notifications' => ['view', 'edit']];
        try {
            $this->controller->get();
            self::fail('A custom role needs an explicit bots area');
        } catch (AccessDeniedException) {
            self::assertSame([], $this->rows);
        }
        $this->role->perms['set:bots'] = ['view'];
        self::assertSame(200, $this->controller->get()->getStatusCode());
        try {
            $this->save(['max' => ['token' => 'private-max-token']]);
            self::fail('View-only cannot save');
        } catch (AccessDeniedException) {
            self::assertSame([], $this->rows);
        }
    }
}
