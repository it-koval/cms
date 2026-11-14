<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ChangeDescriber;
use Itk\Cms\Content\ChangeDetector;
use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Content\FieldValues;
use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\ApiToken;
use Itk\Cms\Entity\Role;
use Itk\Cms\Entity\Setting;
use Itk\Cms\Entity\User;
use Itk\Cms\History\ChangeSource;
use Itk\Cms\History\HistoryService;
use Itk\Cms\History\Snapshots;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\Tool\SettingsGetTool;
use Itk\Cms\Repository\RevisionRepository;
use Itk\Cms\Repository\RoleRepository;
use Itk\Cms\Repository\SettingRepository;
use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\SettingsGroup;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\RoleResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

final class BotSettingsCollisionTest extends TestCase
{
    private Schema $schema;
    private SettingsGroup $group;
    private SettingsService $settings;
    private HistoryService $history;
    private FieldAccess $fields;
    private McpContext $context;
    /** @var array<string, Setting> */
    private array $rows;

    protected function setUp(): void
    {
        $this->group = (new SettingsGroup('site', 'Site'))
            ->fields(Field::text('title', 'Title'), Field::text('telegramBot', 'Telegram connection'))
            ->prepend(Field::text('maxBot', 'MAX connection'));
        $this->schema = new Schema('ru', [], [], [], ['top' => [], 'bottom' => []], [], [], [], ['site' => $this->group], []);
        $this->rows = [
            'title' => new Setting('title', Setting::ALL, 'Public title'),
            'telegramBot' => new Setting('telegramBot', Setting::ALL, ['token' => 'v1.telegram-secret']),
            'maxBot' => new Setting('maxBot', Setting::ALL, ['token' => 'v1.max-secret']),
        ];
        $repo = $this->createStub(SettingRepository::class);
        $repo->method('findAll')->willReturnCallback(fn (): array => \array_values($this->rows));
        $repo->method('find')->willReturnCallback(fn (array $id): ?Setting => $this->rows[$id['key']] ?? null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $user = new User('collision-test@example.com', 'Test', 'admin');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $role = new Role('admin', 'Admin');
        $role->builtin = true;
        $roles = $this->createStub(RoleRepository::class);
        $roles->method('find')->willReturn($role);
        $access = new Access($security, new RoleResolver($this->schema, $roles));
        $this->fields = new FieldAccess($access);
        $html = new HtmlSanitizer($this->schema);
        $values = new FieldValues($html, new AdminLocale('ru', ['ru'], 'ru', new NullLogger()));
        $describer = new ChangeDescriber($html, $values);
        $snapshots = new Snapshots($this->schema, new ChangeDetector($this->schema, $values, $describer), $describer, $values);
        $this->history = new HistoryService($em, $this->createStub(RevisionRepository::class), $snapshots, $this->schema, $security, new ChangeSource(), 20, 30);
        $this->settings = new SettingsService($em, $repo, $this->schema, $values, $this->history, $this->fields, 'ru');
        $this->context = new McpContext(new ApiToken($user, 'Test', 'hash', 'hint', ['read']), $access);
    }

    #[Test]
    public function collidingFieldsAreExcludedFromEverySchemaExport(): void
    {
        self::assertSame(['title'], \array_map(static fn ($field): string => $field->key(), $this->group->fieldList()));
        self::assertSame(['title'], \array_column($this->group->toArray()['fields'], 'k'));
        self::assertNull($this->schema->settingsField('telegramBot'));
        self::assertNull($this->schema->settingsField('maxBot'));
        $json = (string) \json_encode($this->schema->toAdminArray());
        self::assertStringNotContainsString('telegramBot', $json);
        self::assertStringNotContainsString('maxBot', $json);
    }

    #[Test]
    public function genericSettingsReadsExcludeTheCredentialRows(): void
    {
        $all = $this->settings->all();

        self::assertSame('Public title', $all['title']);
        self::assertArrayNotHasKey('telegramBot', $all);
        self::assertArrayNotHasKey('maxBot', $all);
        self::assertSame(['title' => 'Public title'], $this->history->settingsOf('site', $all));
    }

    #[Test]
    public function genericUpdatesCannotOverwriteCredentialsOrRecordThemInHistory(): void
    {
        $result = $this->settings->update(['telegramBot' => 'replacement', 'maxBot' => 'replacement']);

        self::assertSame(['token' => 'v1.telegram-secret'], $this->rows['telegramBot']->value);
        self::assertSame(['token' => 'v1.max-secret'], $this->rows['maxBot']->value);
        self::assertArrayNotHasKey('telegramBot', $result);
        self::assertArrayNotHasKey('maxBot', $result);
    }

    #[Test]
    public function mcpCannotReadCredentialsUnderCollidingInnocentFieldLabels(): void
    {
        $result = (new SettingsGetTool($this->schema, $this->settings, $this->fields))->call([], $this->context);

        self::assertSame(['title' => ['label' => 'Title', 'value' => 'Public title']], $result['groups'][0]['settings']);
        $text = (string) \json_encode($result);
        foreach (['telegramBot', 'maxBot', 'v1.telegram-secret', 'v1.max-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }
}
