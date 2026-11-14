<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Entity\Role;
use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\SchemaBuilder;
use Itk\Cms\Security\RoleResolver;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** Встроенная группа настроек «Аналитика и вебмастера»: поля ядра, поля сайта с теми же ключами остаются, право `set:analytics` только у администратора. */
final class AnalyticsSettingsTest extends KernelTestCase
{
    private const KEYS = ['yandexMetrikaId', 'yandexMetrikaWebvisor', 'googleAnalyticsId', 'yandexVerification', 'googleVerification', 'indexNow'];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true);
    }

    /** @return list<string> */
    private function keysOf(string $group, \Itk\Cms\Schema\Schema $schema): array
    {
        return \array_map(static fn ($f): string => $f->key(), $schema->settingsGroups()[$group]->fieldList());
    }

    #[Test]
    public function everySiteHasTheGroupWithTheSixFields(): void
    {
        $schema = (new SchemaBuilder('ru'))->build();

        self::assertSame(self::KEYS, $this->keysOf('analytics', $schema));
        self::assertSame('Аналитика и вебмастера', $schema->settingsGroups()['analytics']->groupTitle());
        self::assertSame([], $schema->validate());
    }

    #[Test]
    public function aFieldTheSiteDeclaredItselfStaysAndTheRestJoinsIt(): void
    {
        $b = new SchemaBuilder('ru');
        $b->settings('analytics', 'Счётчики сайта')->fields(Field::text('googleAnalyticsId', 'Свой GA')->max(30), Field::text('hotjar', 'Hotjar'));
        $schema = $b->build();

        self::assertSame(['yandexMetrikaId', 'yandexMetrikaWebvisor', 'yandexVerification', 'googleVerification', 'indexNow', 'googleAnalyticsId', 'hotjar'], $this->keysOf('analytics', $schema));
        self::assertSame('Счётчики сайта', $schema->settingsGroups()['analytics']->groupTitle(), 'the site keeps its own title');
        $own = $schema->settingsField('googleAnalyticsId');
        self::assertSame(30, $own?->maxLength());
    }

    #[Test]
    public function aCoreKeyTheSiteDeclaredInAnotherGroupStaysThereAndOnlyThere(): void
    {
        $b = new SchemaBuilder('ru');
        $b->settings('counters', 'Счётчики')->fields(Field::text('yandexMetrikaId', 'Метрика'), Field::text('domain', 'Домен'));
        $schema = $b->build();

        self::assertSame([], $schema->validate());
        self::assertSame(['yandexMetrikaId', 'domain'], $this->keysOf('counters', $schema));
        self::assertSame(['yandexMetrikaWebvisor', 'googleAnalyticsId', 'yandexVerification', 'googleVerification', 'indexNow'], $this->keysOf('analytics', $schema));
        self::assertSame(['siteName', 'organizationType', 'organizationName', 'organizationAddress', 'phone', 'email', 'organizationLogo', 'organizationLinks', 'schemaOrgOff'], $this->keysOf('site', $schema));
    }

    #[Test]
    public function onlyTheAdminRoleHoldsTheRight(): void
    {
        $resolver = self::getContainer()->get(RoleResolver::class);
        $rights = static function (string $key) use ($resolver): array {
            $role = new Role($key, $key);
            $role->builtin = true;

            return $resolver->resolve($role)['perms'];
        };

        self::assertSame(['view', 'edit'], $rights(Role::ADMIN)['set:analytics']);
        foreach ([Role::EDITOR, Role::AUTHOR, Role::REQUESTS] as $key) {
            self::assertArrayNotHasKey('set:analytics', $rights($key), $key);
        }
    }
}
