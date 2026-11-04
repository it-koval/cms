<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Entity\User;
use Itk\Cms\Extension\ItkExtensionInterface;
use Itk\Cms\Extension\NavItem;
use Itk\Cms\Extension\Registry;
use Itk\Cms\Extension\TwigPathsSubscriber;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Security\Viewer;
use Itk\Cms\Tests\Core\Fixture\TestChannel;
use Itk\Cms\Tests\Core\Fixture\TestTemplate;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use Itk\Cms\Tests\Core\Fixture\WarnHealthCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Loader\FilesystemLoader;

/** Расширения: один вызов register() подключает классы-ресурсы, шаблоны Twig, пункты меню, ассеты админки. */
final class ExtensionRegistryTest extends TestCase
{
    private function extension(\Closure $register): ItkExtensionInterface
    {
        return new class($register) implements ItkExtensionInterface {
            public function __construct(private readonly \Closure $register)
            {
            }

            public function register(Registry $registry): void
            {
                ($this->register)($registry);
            }
        };
    }

    #[Test]
    public function extensionsRegisterOnceOnTheFirstQuestion(): void
    {
        $calls = 0;
        $registry = new Registry([
            $this->extension(static function (Registry $r) use (&$calls): void {
                ++$calls;
                $r->resources(TripsCollection::class)->collections(TripsCollection::class)->templates(TestTemplate::class)
                    ->twigPaths('/tmp/x', 'Test')
                    ->menu(NavItem::make('Отчёты', '/x/reports'))
                    ->adminAssets(js: ['/a.js'], css: ['/a.css'])
                    ->notificationChannels(TestChannel::class)->healthChecks(WarnHealthCheck::class);
            }),
            $this->extension(static function (Registry $r): void {
                $r->adminAssets(js: ['/a.js', '/b.js']);
            }),
        ]);

        self::assertSame(0, $calls, 'nothing runs before somebody asks');
        self::assertSame([TripsCollection::class, TestTemplate::class], $registry->resourceClasses(), 'a class is listed once');
        self::assertSame(['js' => ['/a.js', '/b.js'], 'css' => ['/a.css']], $registry->adminAssetList());
        self::assertSame([['dir' => '/tmp/x', 'namespace' => 'Test']], $registry->twigPathList());
        self::assertCount(1, $registry->menuItems());
        self::assertSame([TestChannel::class], $registry->channelClasses());
        self::assertSame([WarnHealthCheck::class], $registry->healthCheckClasses());
        self::assertSame(1, $calls);
    }

    #[Test]
    public function aClassThatIsNotAResourceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Registry())->resources(\stdClass::class);
    }

    #[Test]
    public function theResourceRegistryJoinsTaggedClassesExtensionClassesAndAddedOnes(): void
    {
        $tagged = new TestTemplate();
        $registry = new Registry([$this->extension(static fn (Registry $r): Registry => $r->resources(TripsCollection::class, TestTemplate::class))]);
        $resources = new ResourceRegistry([$tagged], $registry);

        $all = $resources->all();
        self::assertSame([TestTemplate::class, TripsCollection::class], \array_map(static fn ($r): string => $r::class, $all), 'one object per class; templates before collections');
        self::assertSame($tagged, $all[0], 'the container\'s object wins over a new one');

        $other = new TripsCollection();
        $resources->add($other);
        self::assertSame($other, $resources->all()[1], 'an added object replaces the one made by new');
    }

    #[Test]
    public function twigPathsOfExtensionsAreAddedToTheLoader(): void
    {
        $dir = \sys_get_temp_dir() . '/itk-ext-' . \bin2hex(\random_bytes(4));
        \mkdir($dir);
        \file_put_contents($dir . '/block.html.twig', 'ok');
        try {
            $loader = new FilesystemLoader();
            $registry = new Registry([$this->extension(static fn (Registry $r): Registry => $r->twigPaths($dir, 'TestPkg'))]);
            self::assertFalse($loader->exists('@TestPkg/block.html.twig'));

            $subscriber = new TwigPathsSubscriber($registry, $loader);
            $subscriber->add();
            $subscriber->add();
            self::assertTrue($loader->exists('@TestPkg/block.html.twig'));
        } finally {
            \unlink($dir . '/block.html.twig');
            \rmdir($dir);
        }
    }

    #[Test]
    public function aNavItemChecksItsViewer(): void
    {
        $viewer = Viewer::of(new User('v@example.com', 'V', 'custom'), ['perms' => ['col:tours' => ['view']], 'templates' => null]);

        self::assertTrue(NavItem::make('А', '/a')->visibleTo($viewer));
        self::assertTrue(NavItem::make('А', '/a')->canSee('col:tours')->visibleTo($viewer));
        self::assertFalse(NavItem::make('А', '/a')->canSee('set:users')->visibleTo($viewer));
        self::assertFalse(NavItem::make('А', '/a')->canSee(static fn (Viewer $v): bool => 'admin' === $v->role())->visibleTo($viewer));
    }
}
