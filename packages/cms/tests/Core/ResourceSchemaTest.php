<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Attribute\Order;
use Itk\Cms\Entity\User;
use Itk\Cms\Extension\Registry;
use Itk\Cms\Field\Text;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\ResourceApplier;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\SectionResource;
use Itk\Cms\Resource\SettingsResource;
use Itk\Cms\Resource\TemplateResource;
use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\SchemaBuilder;
use Itk\Cms\Schema\SectionType;
use Itk\Cms\Security\Viewer;
use Itk\Cms\Tests\Core\Fixture\TestTemplate;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Классы-ресурсы собираются в тот же Schema, что и config/itk/schema.php; повтор ключа — понятная ошибка. */
final class ResourceSchemaTest extends TestCase
{
    private function build(callable $define, object ...$resources): Schema
    {
        $b = new SchemaBuilder('ru');
        $define($b);
        foreach ($resources as $r) {
            ResourceApplier::apply($b, $r);
        }

        return $b->build();
    }

    #[Test]
    public function aCollectionClassBecomesACollectionOfTheSchema(): void
    {
        $schema = $this->build(static function (SchemaBuilder $s): void {
            $s->collection('old', 'Старая')->fields(Field::text('title', 'Название'));
        }, new TripsCollection());

        self::assertSame(['old', 'test_trips'], \array_keys($schema->collections()));
        $c = $schema->collection('test_trips');
        self::assertInstanceOf(TripsCollection::class, $c->resource());
        $a = $c->toArray();
        self::assertSame('Тестовые поездки', $a['title']);
        self::assertSame(['title', 'start', 'seats', 'state', 'level', 'hot', 'secret', 'sys', 'note'], \array_column($a['tabs'][0]['fields'], 'k'));
        self::assertSame(['start', 'seats', 'state', 'level', 'hot'], \array_column($a['filters'], 'k'));
        self::assertSame(['start' => 'asc'], $a['defaultSort']);
        self::assertSame(3, $a['perPage']);
        self::assertSame(['title'], $a['searchKeys']);
        self::assertSame([['label' => 'Всего', 'kind' => 'count'], ['label' => 'Открыто', 'kind' => 'count'], ['label' => 'Горячих', 'kind' => 'count'], ['label' => 'Мест', 'kind' => 'sum']], $a['metrics']);
        self::assertSame([['type' => 'table']], $a['views']);
        self::assertSame(['tag', 'secretive', 'hidden', 'export', 'go', 'jump', 'fails', 'publish-selected', 'unpublish-selected', 'delete-selected'], \array_column($a['actions'], 'key'));
        self::assertSame(['group' => 'Тест', 'groupIcon' => 'box', 'groupOrder' => 77, 'icon' => 'map', 'order' => 5, 'hidden' => false, 'canSee' => null], $c->menuRule());
    }

    #[Test]
    public function aCollectionOfSchemaPhpHasTheSameNewOptions(): void
    {
        $schema = $this->build(static function (SchemaBuilder $s): void {
            $s->collection('tours', 'Поездки')->views(['table', 'calendar' => ['start' => 'from', 'end' => 'to', 'title' => 'title']])->perPage(20)->defaultSort(['-from', 'title'])
                ->fields(Field::date('from', 'С')->sortable()->filterable(), Field::date('to', 'По'), Field::text('title', 'Название'));
        });
        $a = $schema->collection('tours')->toArray();
        self::assertSame([['type' => 'table'], ['type' => 'calendar', 'start' => 'from', 'end' => 'to', 'title' => 'title']], $a['views']);
        self::assertSame('table', $a['view']);
        self::assertSame(['from' => 'desc', 'title' => 'asc'], $a['defaultSort']);
        self::assertSame(20, $a['perPage']);
    }

    #[Test]
    public function aTemplateClassMergesWithTheCoreAndChangesACoreTemplate(): void
    {
        $schema = $this->build(static function (SchemaBuilder $s): void {
        }, new TestTemplate());
        $t = $schema->template('test_tpl')->toArray();
        self::assertSame('Тестовый шаблон', $t['name']);
        self::assertSame('Для тестов.', $t['about']);
        self::assertSame(['default'], $t['parents']);
        self::assertSame('Тестовые страницы', $t['shortcut']);
        self::assertTrue($t['leaf']);
        self::assertSame(['sub', 'secret'], \array_column($t['fields'], 'k'));
        self::assertSame(['home', 'default', 'blog', 'blog_category', 'blog_article', 'test_tpl'], \array_keys($schema->templates()));

        $override = new class extends \Itk\Cms\Resource\TemplateResource {
            public function key(): string
            {
                return 'blog_article';
            }

            public function title(): string
            {
                return 'Новость';
            }

            public function fields(): iterable
            {
                return [];
            }
        };
        $changed = $this->build(static function (SchemaBuilder $s): void {
        }, $override);
        self::assertSame('Новость', $changed->template('blog_article')->title());
        self::assertSame(['title', 'date', 'cover', 'excerpt', 'body'], \array_column($changed->template('blog_article')->toArray()['fields'], 'k'), 'no fields in the class: the core ones stay');
    }

    #[Test]
    public function theDefaultGroupOfSectionsIsOneGroupInEveryLanguageOfTheReader(): void
    {
        $bare = new class extends SectionResource {
            public function key(): string
            {
                return 'test_bare';
            }

            public function title(): string
            {
                return 'Голая';
            }

            public function fields(): iterable
            {
                return [];
            }
        };
        $written = new class extends SectionResource {
            public function key(): string
            {
                return 'test_written';
            }

            public function title(): string
            {
                return 'Написанная';
            }

            public function fields(): iterable
            {
                return [];
            }

            public function group(): string
            {
                return 'Тексты и картинки'; // the literal a generated class holds
            }
        };
        $schema = $this->build(static function (SchemaBuilder $s): void {
        }, $bare, $written);

        try {
            foreach (['ru' => 'Тексты и картинки', 'kk' => 'Мәтін мен суреттер', 'en' => 'Text and images'] as $locale => $group) {
                I18n::useLocale($locale);
                self::assertSame($group, $schema->section('test_bare')->groupName());
                self::assertSame($group, $schema->section('test_written')->groupName());
                self::assertSame([SectionType::FIRST_SCREEN, $group], $schema->sectionGroups());
            }
        } finally {
            I18n::useLocale('ru');
        }
    }

    #[Test]
    public function theSiteGroupOfTheCoreIsReadInTheLanguageOfTheReader(): void
    {
        $schema = (new SchemaBuilder('ru'))->build();
        $site = $schema->toAdminArray()['settings']['groups'][0];

        try {
            foreach (['ru' => ['Сайт', 'Название сайта', 'Адрес сайта'], 'kk' => ['Сайт', 'Сайт атауы', 'Сайт мекенжайы'], 'en' => ['Site', 'Site name', 'Site address']] as $locale => $expected) {
                I18n::useLocale($locale);
                $group = $schema->toAdminArray()['settings']['groups'][0];
                self::assertSame('site', $group['key']);
                self::assertSame($expected, [$group['title'], ...\array_slice(\array_column($group['fields'], 'label'), 0, 2)]);
                self::assertNotSame('', $group['fields'][1]['help']);
                foreach ($group['fields'] as $field) {
                    self::assertStringStartsNotWith('model.', $field['label'], 'a label is translated in ' . $locale);
                    self::assertStringStartsNotWith('model.', (string) ($field['help'] ?? ''), 'a help is translated in ' . $locale);
                }
            }
        } finally {
            I18n::useLocale('ru');
        }
        self::assertSame('site', $site['key']);
    }

    #[Test]
    public function aSectionClassHasItsOwnTwigTemplate(): void
    {
        $section = new class extends SectionResource {
            public function key(): string
            {
                return 'test_banner';
            }

            public function title(): string
            {
                return 'Баннер';
            }

            public function fields(): iterable
            {
                yield Text::make('Текст', 'text');
            }

            public function group(): string
            {
                return 'Первый экран';
            }

            public function template(): string
            {
                return 'custom/banner.html.twig';
            }
        };
        $plain = new class extends SectionResource {
            public function key(): string
            {
                return 'test_plain';
            }

            public function title(): string
            {
                return 'Простая';
            }

            public function fields(): iterable
            {
                return [];
            }
        };
        $schema = $this->build(static function (SchemaBuilder $s): void {
        }, $section, $plain);
        self::assertSame('custom/banner.html.twig', $schema->section('test_banner')->templatePath());
        self::assertSame('sections/test_plain.html.twig', $schema->section('test_plain')->templatePath());
        self::assertSame('Первый экран', $schema->section('test_banner')->toArray()['group']);
    }

    #[Test]
    public function aSettingsClassAddsAGroup(): void
    {
        $settings = new class extends SettingsResource {
            public function key(): string
            {
                return 'test_contacts';
            }

            public function title(): string
            {
                return 'Тестовые контакты';
            }

            public function fields(): iterable
            {
                yield Text::make('Телефон', 'testPhone')->required();
            }
        };
        $schema = $this->build(static function (SchemaBuilder $s): void {
        }, $settings);
        self::assertSame('testPhone', $schema->settingsField('testPhone')?->key());
        self::assertContains('set:test_contacts', \array_column($schema->permissionAreas(), 'key'));
    }

    #[Test]
    public function aKeyDescribedInSchemaPhpAndInAClassIsAnErrorThatNamesBoth(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The collection "test_trips" is described twice: in config/itk/schema.php and in ' . TripsCollection::class);
        $this->build(static function (SchemaBuilder $s): void {
            $s->collection('test_trips', 'Другая');
        }, new TripsCollection());
    }

    #[Test]
    public function aKeyDescribedByTwoClassesIsAnError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/template "test_tpl" is described twice: in .*TestTemplate and in /');
        $twin = new class extends \Itk\Cms\Resource\TemplateResource {
            public function key(): string
            {
                return 'test_tpl';
            }

            public function title(): string
            {
                return 'Двойник';
            }

            public function fields(): iterable
            {
                return [];
            }
        };
        $this->build(static function (SchemaBuilder $s): void {
        }, new TestTemplate(), $twin);
    }

    #[Test]
    public function aBadKeyIsRejected(): void
    {
        $bad = new class extends \Itk\Cms\Resource\CollectionResource {
            public function key(): string
            {
                return 'bad key!';
            }

            public function title(): string
            {
                return 'X';
            }

            public function fields(): iterable
            {
                return [];
            }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be Latin letters');
        $this->build(static function (SchemaBuilder $s): void {
        }, $bad);
    }

    #[Test]
    public function aClassThatReturnsSomethingElseThanFieldsIsRejected(): void
    {
        $bad = new class extends \Itk\Cms\Resource\CollectionResource {
            public function key(): string
            {
                return 'test_bad';
            }

            public function title(): string
            {
                return 'X';
            }

            public function fields(): iterable
            {
                // @phpstan-ignore generator.valueType (Verify the runtime guard for resources without static analysis.)
                yield 'not a field';
            }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('fields() must return');
        $this->build(static function (SchemaBuilder $s): void {
        }, $bad);
    }

    #[Test]
    public function theExportForAViewerLeavesOutWhatHeMayNotSee(): void
    {
        $schema = $this->build(static function (SchemaBuilder $s): void {
        }, new TripsCollection(), new TestTemplate());
        $editor = Viewer::of(new User('e@example.com', 'E', 'editor'), ['perms' => ['col:test_trips' => ['view', 'edit', 'publish', 'delete']], 'templates' => null]);
        $admin = Viewer::of(new User('a@example.com', 'A', 'admin'), ['perms' => ['col:test_trips' => ['view', 'edit', 'publish', 'delete'], 'set:users' => ['view'], 'action:test_trips:secretive' => ['view']], 'templates' => null]);

        $forEditor = $schema->toAdminArray($editor);
        $fields = \array_column($forEditor['collections'][0]['tabs'][0]['fields'], 'k');
        self::assertNotContains('secret', $fields);
        self::assertSame(['tag', 'export', 'go', 'jump', 'fails', 'publish-selected', 'unpublish-selected', 'delete-selected'], \array_column($forEditor['collections'][0]['actions'], 'key'), 'no permission() line, no canSee');
        self::assertNotContains('secret', \array_column($forEditor['templates'][5]['fields'], 'k'));

        $forAdmin = $schema->toAdminArray($admin);
        self::assertContains('secret', \array_column($forAdmin['collections'][0]['tabs'][0]['fields'], 'k'));
        self::assertSame(['tag', 'secretive', 'export', 'go', 'jump', 'fails', 'publish-selected', 'unpublish-selected', 'delete-selected'], \array_column($forAdmin['collections'][0]['actions'], 'key'));

        // without a viewer everything stays (backward compatible export)
        self::assertContains('secret', \array_column($schema->toAdminArray()['collections'][0]['tabs'][0]['fields'], 'k'));
    }

    #[Test]
    public function permissionAreasComeFromTheResourcesAndTheirActions(): void
    {
        $schema = $this->build(static function (SchemaBuilder $s): void {
        }, new TripsCollection());
        $areas = [];
        foreach ($schema->permissionAreas() as $a) {
            $areas[$a['key']] = $a;
        }
        self::assertSame(['view', 'edit', 'publish', 'delete'], $areas['col:test_trips']['actions']);
        self::assertSame('Тестовые поездки', $areas['col:test_trips']['label']);
        self::assertSame(['view'], $areas['action:test_trips:secretive']['actions']);
        self::assertSame('actions', $areas['action:test_trips:secretive']['group']);
        self::assertArrayNotHasKey('action:test_trips:tag', $areas, 'only actions with permission() get a line');
    }

    #[Test]
    public function aCollectionClassSaysWhatAnEmptyListLooksLike(): void
    {
        $plain = new class extends CollectionResource {
            public function key(): string
            {
                return 'test_plain';
            }

            public function title(): string
            {
                return 'Простая';
            }

            public function fields(): iterable
            {
                yield Text::make('Название', 'title');
            }
        };
        $own = new class extends CollectionResource {
            public function key(): string
            {
                return 'test_own';
            }

            public function title(): string
            {
                return 'Своя';
            }

            public function fields(): iterable
            {
                yield Text::make('Название', 'title');
            }

            public function emptyState(): array
            {
                return ['title' => 'Поездок пока нет', 'text' => 'Добавьте первую.'];
            }
        };
        $schema = $this->build(static function (SchemaBuilder $s): void {
        }, $plain, $own);

        self::assertSame(['title' => 'Список пока пуст', 'text' => 'Добавьте первую запись.'], $schema->collection('test_plain')->toArray()['empty']);
        self::assertSame(['title' => 'Поездок пока нет', 'text' => 'Добавьте первую.'], $schema->collection('test_own')->toArray()['empty']);
    }

    #[Test]
    public function orderPutsTemplatesInTheListInTheWishedPlace(): void
    {
        $late = new #[Order(20)] class extends TemplateResource {
            public function key(): string
            {
                return 'a_late';
            }

            public function title(): string
            {
                return 'Поздний';
            }

            public function fields(): iterable
            {
                return [];
            }
        };
        $early = new #[Order(10)] class extends TemplateResource {
            public function key(): string
            {
                return 'z_early';
            }

            public function title(): string
            {
                return 'Ранний';
            }

            public function fields(): iterable
            {
                return [];
            }
        };
        $unordered = new class extends TemplateResource {
            public function key(): string
            {
                return 'b_unordered';
            }

            public function title(): string
            {
                return 'Без порядка';
            }

            public function fields(): iterable
            {
                return [];
            }
        };

        $keys = static fn (array $list): array => \array_map(static fn ($r): string => $r->key(), $list);
        self::assertSame(['z_early', 'a_late', 'b_unordered'], $keys((new ResourceRegistry([$unordered, $late, $early], new Registry([])))->all()), 'by #[Order], then (100) by key');
    }
}
