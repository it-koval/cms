<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Command\MakeCollectionCommand;
use Itk\Cms\Command\MakeFieldCommand;
use Itk\Cms\Command\MakePluginCommand;
use Itk\Cms\Command\MakeSectionCommand;
use Itk\Cms\Command\MakeSettingsCommand;
use Itk\Cms\Command\MakeTemplateCommand;
use Itk\Cms\Extension\ItkExtensionInterface;
use Itk\Cms\Extension\Registry;
use Itk\Cms\Field\AbstractField;
use Itk\Cms\Generator\CollectionClassBuilder;
use Itk\Cms\Generator\CollectionSpecReader;
use Itk\Cms\Generator\FileWriter;
use Itk\Cms\Generator\MakeContext;
use Itk\Cms\Generator\Naming;
use Itk\Cms\Generator\ProjectLayout;
use Itk\Cms\Generator\SkeletonRenderer;
use Itk\Cms\Generator\TableReader;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\SectionResource;
use Itk\Cms\Resource\SettingsResource;
use Itk\Cms\Resource\TemplateResource;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;

/** Генераторы itk:make:*: класс (и заготовка Twig / JS) в src/Itk/…, без перезаписи без --force; пробный запуск ничего не пишет. */
final class GeneratorsTest extends TestCase
{
    private string $project;
    private MakeContext $make;

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/itk_gen_' . \bin2hex(\random_bytes(4));
        \mkdir($this->project . '/config/itk', 0775, true);
        \file_put_contents($this->project . '/composer.json', '{"autoload": {"psr-4": {"Gen\\\\": "src/"}}}');
        $this->make = new MakeContext(
            new ProjectLayout($this->project),
            new SkeletonRenderer(),
            new FileWriter(),
            new ResourceRegistry([], new Registry([])),
            $this->project . '/config/itk/schema.php',
        );
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    private function remove(string $path): void
    {
        if (\is_dir($path)) {
            foreach (\scandir($path) ?: [] as $f) {
                '.' !== $f && '..' !== $f && $this->remove($path . '/' . $f);
            }
            \rmdir($path);
        } elseif (\file_exists($path)) {
            \unlink($path);
        }
    }

    private function collectionCommand(): MakeCollectionCommand
    {
        $skeleton = new SkeletonRenderer();
        $none = new class implements ContainerInterface {
            public function get(string $id): never
            {
                throw new \LogicException('not needed');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return new MakeCollectionCommand($this->make, new CollectionClassBuilder($skeleton), new CollectionSpecReader(), new TableReader(), $this->createStub(ManagerRegistry::class), $none);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function exec(Command $command, array $input): CommandTester
    {
        $t = new CommandTester($command);
        $t->execute($input, ['interactive' => false]);

        return $t;
    }

    private function code(string $path): string
    {
        $code = (string) \file_get_contents($this->project . '/' . $path);
        self::assertNotEmpty(\token_get_all($code, \TOKEN_PARSE));

        return $code;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $base
     *
     * @return class-string<T>
     */
    private function generatedClass(string $path, string $name, string $base): string
    {
        require_once $this->project . '/' . $path;
        self::assertTrue(\is_a($name, $base, true), $name . ' must extend or implement ' . $base);

        return $name;
    }

    #[Test]
    public function namesComeFromKeys(): void
    {
        self::assertSame('BlogArticle', Naming::studly('blog_article'));
        self::assertSame('blogArticle', Naming::camel('blog-article'));
        self::assertSame('DateStart', Naming::studly('dateStart'));
        self::assertSame('Date start', Naming::humanize('dateStart'));
        self::assertSame('Дата начала', Naming::label('dateStart'));
        self::assertSame('tour_list', Naming::keyOf('modx_tour_list', 'modx_'));
        self::assertSame('t_2024_plan', Naming::keyOf('2024-plan'));
        self::assertSame("'it\\'s \\\\'", Naming::php("it's \\"));
        self::assertTrue(Naming::isKey('test_1'));
        self::assertFalse(Naming::isKey('Test'));
        self::assertFalse(Naming::isKey('1test'));
    }

    #[Test]
    public function theNamespaceFollowsThePsr4MapOfComposer(): void
    {
        $l = new ProjectLayout($this->project);
        self::assertSame('Gen\\Itk\\Collection', $l->namespaceOf('src/Itk/Collection'));
        self::assertSame('Gen', $l->namespaceOf('src/'));
        foreach (['lib/x', '../x', '.git/x', ''] as $bad) {
            try {
                $l->namespaceOf($bad);
                self::fail('"' . $bad . '" must be refused');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        // no composer.json at all: a standard Symfony project
        $this->remove($this->project . '/composer.json');
        self::assertSame('App\\Itk\\Section', $l->namespaceOf('src/Itk/Section'));
    }

    #[Test]
    public function aTemplateIsAClassAndATwigStub(): void
    {
        $t = $this->exec(new MakeTemplateCommand($this->make), ['key' => 'test_gen_tpl', 'name' => 'Тестовый шаблон']);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        self::assertStringContainsString('src/Itk/Template/TestGenTplTemplate.php', $t->getDisplay());

        $code = $this->code('src/Itk/Template/TestGenTplTemplate.php');
        self::assertStringContainsString('namespace Gen\\Itk\\Template;', $code);
        $class = $this->generatedClass('src/Itk/Template/TestGenTplTemplate.php', 'Gen\\Itk\\Template\\TestGenTplTemplate', TemplateResource::class);
        $r = new $class();
        self::assertSame('test_gen_tpl', $r->key());
        self::assertSame('Тестовый шаблон', $r->title());
        self::assertSame([], [...$r->fields()]);

        $twig = (string) \file_get_contents($this->project . '/templates/pages/test_gen_tpl.html.twig');
        self::assertStringContainsString('{{ itk_render_sections(page) }}', $twig);
        self::assertStringContainsString('“test_gen_tpl”', $twig);
        (new Environment(new ArrayLoader()))->tokenize(new Source($twig, 'test_gen_tpl'));
    }

    #[Test]
    public function aSectionHasAGroupAndAStubOfItsOwn(): void
    {
        $t = $this->exec(new MakeSectionCommand($this->make), ['key' => 'test_gen_sec', 'name' => 'Секция «Тест»', '--group' => 'Формы']);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        $this->code('src/Itk/Section/TestGenSecSection.php');
        $class = $this->generatedClass('src/Itk/Section/TestGenSecSection.php', 'Gen\\Itk\\Section\\TestGenSecSection', SectionResource::class);
        $r = new $class();
        self::assertSame(['test_gen_sec', 'Секция «Тест»', 'Формы'], [$r->key(), $r->title(), $r->group()]);
        self::assertSame(['headline', 'text', 'image'], \array_map(static fn ($f): string => $f->key(), [...$r->fields()]));
        $twig = (string) \file_get_contents($this->project . '/templates/sections/test_gen_sec.html.twig');
        self::assertStringContainsString('class="test-gen-sec"', $twig);
        self::assertStringContainsString('itk_section_attrs(section)', $twig);
    }

    #[Test]
    public function aSettingsGroupExplainsHowToReadItsValues(): void
    {
        $t = $this->exec(new MakeSettingsCommand($this->make), ['key' => 'test_gen_set', 'name' => 'Тест', '--dir' => 'src/Itk/Settings']);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        $code = $this->code('src/Itk/Settings/TestGenSetSettings.php');
        self::assertStringContainsString("itk_settings('testGenSetText')", $code);
        $class = $this->generatedClass('src/Itk/Settings/TestGenSetSettings.php', 'Gen\\Itk\\Settings\\TestGenSetSettings', SettingsResource::class);
        $r = new $class();
        self::assertSame(['testGenSetText'], \array_map(static fn ($f): string => $f->key(), [...$r->fields()]));
    }

    #[Test]
    public function aFieldIsAPhpTypeAndItsAdminComponent(): void
    {
        $t = $this->exec(new MakeFieldCommand($this->make), ['key' => 'test_gen_fld', 'name' => 'Рейтинг']);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        $this->code('src/Itk/Field/TestGenFldField.php');
        $class = $this->generatedClass('src/Itk/Field/TestGenFldField.php', 'Gen\\Itk\\Field\\TestGenFldField', AbstractField::class);
        $f = $class::make('Оценка', 'rate')->required();
        self::assertSame(['k' => 'rate', 't' => 'test_gen_fld', 'label' => 'Оценка', 'req' => true], \array_intersect_key($f->toArray(), \array_flip(['k', 't', 'label', 'req'])));

        $js = (string) \file_get_contents($this->project . '/public/itk/fields/test_gen_fld.js');
        self::assertStringContainsString("fields: { 'test_gen_fld': TestGenFldField }", $js);
        self::assertStringContainsString("adminAssets(js: ['/itk/fields/test_gen_fld.js'])", $js);
        self::assertStringNotContainsString('@@', $js);
    }

    #[Test]
    public function aPluginIsAnExtensionWithAMenuItemAndAScript(): void
    {
        $t = $this->exec(new MakePluginCommand($this->make), ['key' => 'test_gen_plg', 'name' => 'Отчёты «для своих»']);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        $this->code('src/Itk/Plugin/TestGenPlgExtension.php');
        $class = $this->generatedClass('src/Itk/Plugin/TestGenPlgExtension.php', 'Gen\\Itk\\Plugin\\TestGenPlgExtension', ItkExtensionInterface::class);

        $registry = new Registry([new $class()]);
        self::assertSame(['/itk/plugins/test_gen_plg/admin.js'], $registry->adminAssetList()['js']);
        self::assertCount(1, $registry->menuItems());

        $js = (string) \file_get_contents($this->project . '/public/itk/plugins/test_gen_plg/admin.js');
        self::assertStringContainsString("panels: { 'test_gen_plg': TestGenPlgPanel }", $js);
        self::assertStringContainsString('h1\', "Отчёты «для своих»")', $js);
    }

    #[Test]
    public function aFileThatExistsIsNeverOverwrittenWithoutForce(): void
    {
        $cmd = new MakeTemplateCommand($this->make);
        $this->exec($cmd, ['key' => 'test_gen_keep']);
        $class = $this->project . '/src/Itk/Template/TestGenKeepTemplate.php';
        \file_put_contents($class, "<?php\n// mine\n");

        $t = $this->exec($cmd, ['key' => 'test_gen_keep']);
        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertStringContainsString('--force', $t->getDisplay());
        self::assertSame("<?php\n// mine\n", \file_get_contents($class));

        $t = $this->exec($cmd, ['key' => 'test_gen_keep', '--force' => true]);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        self::assertStringContainsString('final class TestGenKeepTemplate', (string) \file_get_contents($class));
    }

    #[Test]
    public function aStubOfTheSiteIsKeptWhenOnlyTheClassIsMissing(): void
    {
        $cmd = new MakeSectionCommand($this->make);
        $twig = $this->project . '/templates/sections/test_gen_stub.html.twig';
        \mkdir(\dirname($twig), 0775, true);
        \file_put_contents($twig, '{# my markup #}');

        $t = $this->exec($cmd, ['key' => 'test_gen_stub']);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        self::assertSame('{# my markup #}', \file_get_contents($twig));
        self::assertStringContainsString('уже есть, не тронут', $t->getDisplay());
        self::assertFileExists($this->project . '/src/Itk/Section/TestGenStubSection.php');
    }

    #[Test]
    public function aDryRunShowsTheClassAndWritesNothing(): void
    {
        $t = $this->exec(new MakeSectionCommand($this->make), ['key' => 'test_gen_dry', '--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        self::assertStringContainsString('будет создан', $t->getDisplay());
        self::assertStringContainsString('final class TestGenDrySection', $t->getDisplay());
        self::assertDirectoryDoesNotExist($this->project . '/src');
        self::assertDirectoryDoesNotExist($this->project . '/templates');
    }

    #[Test]
    public function aKeyThatIsTakenIsRefused(): void
    {
        $registry = new ResourceRegistry([new TripsCollection()], new Registry([]));
        $make = new MakeContext(new ProjectLayout($this->project), new SkeletonRenderer(), new FileWriter(), $registry, $this->project . '/config/itk/schema.php');
        \file_put_contents($this->project . '/config/itk/schema.php', "<?php\nreturn static function (\$s): void {\n    \$s->section('old_one', 'Старая')->fields();\n};\n");

        $t = $this->exec(new MakeCollectionCommand($make, new CollectionClassBuilder(new SkeletonRenderer()), new CollectionSpecReader(), new TableReader(), $this->createStub(ManagerRegistry::class), $this->createStub(ContainerInterface::class)), ['key' => 'test_trips']);
        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertStringContainsString(TripsCollection::class, $t->getDisplay());

        $t = $this->exec(new MakeSectionCommand($make), ['key' => 'old_one']);
        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertStringContainsString('config/itk/schema.php', $t->getDisplay());
        self::assertDirectoryDoesNotExist($this->project . '/src');

        // the same key of ANOTHER kind is free
        $t = $this->exec(new MakeTemplateCommand($make), ['key' => 'old_one']);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
    }

    #[Test]
    public function badInputIsRefusedBeforeAnythingIsWritten(): void
    {
        $cmd = new MakeTemplateCommand($this->make);
        self::assertSame(Command::INVALID, $this->exec($cmd, ['key' => 'Bad-Key'])->getStatusCode());
        self::assertSame(Command::INVALID, $this->exec($cmd, [])->getStatusCode(), 'no key and no questions');
        $t = $this->exec($cmd, ['key' => 'test_gen_bad', '--dir' => '../outside']);
        self::assertSame(Command::INVALID, $t->getStatusCode());
        $t = $this->exec($cmd, ['key' => 'test_gen_bad', '--dir' => 'lib/Templates']);
        self::assertStringContainsString('автозагрузку', $t->getDisplay());
        self::assertDirectoryDoesNotExist($this->project . '/src');
        self::assertDirectoryDoesNotExist($this->project . '/lib');
    }

    #[Test]
    public function aCollectionWithoutASourceStartsWithTwoFields(): void
    {
        $t = $this->exec($this->collectionCommand(), ['key' => 'test_gen_plain', 'name' => 'Простая', '--group' => 'Каталог', '--icon' => 'box']);
        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        $code = $this->code('src/Itk/Collection/TestGenPlainCollection.php');
        self::assertStringContainsString("#[Group('Каталог')]", $code);
        $class = $this->generatedClass('src/Itk/Collection/TestGenPlainCollection.php', 'Gen\\Itk\\Collection\\TestGenPlainCollection', CollectionResource::class);
        $r = new $class();
        self::assertSame(['name', 'photo'], \array_map(static fn ($f): string => $f->key(), [...$r->fields()]));
        self::assertSame(['box', 'name'], [$r->icon(), $r->titleField()]);
        self::assertStringContainsString("'Фото'", $code, 'the label of the photo comes from the catalogue (model.label_photo), in the language of the person who runs the command');
    }

    #[Test]
    public function aCollectionComesFromAJsonDescription(): void
    {
        \file_put_contents($this->project . '/trips.json', (string) \json_encode([
            'key' => 'test_gen_json', 'title' => 'Поездки из файла', 'singular' => 'поездка', 'icon' => 'map',
            'fields' => [
                ['key' => 'city', 'label' => 'Город', 'type' => 'text', 'required' => true, 'max' => 80],
                ['key' => 'about', 'type' => 'textarea'],
                ['key' => 'seats', 'label' => 'Мест', 'type' => 'number'],
                ['key' => 'hot', 'label' => 'Горячая', 'type' => 'toggle'],
                ['key' => 'level', 'label' => 'Сложность', 'type' => 'select', 'options' => ['easy', 'hard']],
                ['key' => 'start', 'label' => 'Начало', 'type' => 'date'],
                ['key' => 'at', 'label' => 'Время', 'type' => 'datetime'],
                ['key' => 'cover', 'label' => 'Обложка', 'type' => 'image'],
            ],
        ], \JSON_UNESCAPED_UNICODE));

        $t = $this->exec($this->collectionCommand(), ['--from' => 'trips.json']);
        self::assertSame(Command::SUCCESS, $t->getStatusCode(), $t->getDisplay());
        $code = $this->code('src/Itk/Collection/TestGenJsonCollection.php');
        self::assertStringContainsString("yield Text::make('Город', 'city')->required()->max(80)->searchable()->sortable();", $code);
        self::assertStringContainsString("yield Textarea::make('About', 'about');", $code);
        self::assertStringContainsString("yield Select::make('Сложность', 'level')->options(['easy' => 'easy', 'hard' => 'hard'])->filterable();", $code);
        self::assertStringContainsString("yield Date::make('Начало', 'start')->sortable()->filterable();", $code);
        self::assertStringContainsString("yield DateTime::make('Время', 'at')->sortable()->filterable();", $code);

        $class = $this->generatedClass('src/Itk/Collection/TestGenJsonCollection.php', 'Gen\\Itk\\Collection\\TestGenJsonCollection', CollectionResource::class);
        $r = new $class();
        self::assertSame(['Поездки из файла', 'поездка', 'map', 'city'], [$r->title(), $r->singular(), $r->icon(), $r->titleField()]);
        self::assertSame(
            ['text', 'textarea', 'number', 'toggle', 'select', 'text', 'text', 'image'],
            \array_map(static fn ($f): string => $f->toArray()['t'], [...$r->fields()]),
        );
    }

    #[Test]
    public function reservedCollectionKeysAreRejectedBeforeWritingAClass(): void
    {
        foreach (['status', 'locales', 'position'] as $key) {
            \file_put_contents($this->project . '/reserved.json', \json_encode([
                'key' => 'test_gen_reserved', 'fields' => [['key' => $key, 'type' => 'text']],
            ], \JSON_THROW_ON_ERROR));
            $result = $this->exec($this->collectionCommand(), ['--from' => 'reserved.json']);
            self::assertSame(Command::INVALID, $result->getStatusCode(), $key);
            self::assertStringContainsString($key, $result->getDisplay());
            self::assertDirectoryDoesNotExist($this->project . '/src');
        }
    }

    #[Test]
    public function aBadJsonDescriptionIsExplained(): void
    {
        $cmd = $this->collectionCommand();
        \file_put_contents($this->project . '/bad.json', '{"key": "test_gen_bad", "fields": [{"key": "x", "type": "repeater"}]}');
        $t = $this->exec($cmd, ['--from' => 'bad.json']);
        self::assertSame(Command::INVALID, $t->getStatusCode());
        self::assertStringContainsString('repeater', $t->getDisplay());

        \file_put_contents($this->project . '/bad.json', '{not json');
        self::assertStringContainsString('не JSON', $this->exec($cmd, ['--from' => 'bad.json'])->getDisplay());
        \file_put_contents($this->project . '/bad.json', '{"key": "test_gen_bad", "fields": []}');
        self::assertStringContainsString('нет полей', $this->exec($cmd, ['--from' => 'bad.json'])->getDisplay());
        self::assertStringContainsString('не найден', $this->exec($cmd, ['--from' => 'missing.json'])->getDisplay());
        self::assertDirectoryDoesNotExist($this->project . '/src');
    }

    #[Test]
    public function importNeedsASourceOfRows(): void
    {
        $t = $this->exec($this->collectionCommand(), ['key' => 'test_gen_norows', '--import' => true]);
        self::assertSame(Command::INVALID, $t->getStatusCode());
        self::assertStringContainsString('--import работает с таблицей', $t->getDisplay());
    }

    #[Test]
    public function aBadMapIsExplained(): void
    {
        $t = $this->exec($this->collectionCommand(), ['key' => 'test_gen_map', '--from' => 'table:test_nothing', '--map' => 'city']);
        self::assertSame(Command::INVALID, $t->getStatusCode());
        self::assertStringContainsString('--map', $t->getDisplay());
    }

    #[Test]
    public function builtinFieldTypesCannotBeReplacedByTheGenerator(): void
    {
        foreach (['image', 'repeater', 'relation', 'readonly', 'text'] as $key) {
            $t = $this->exec(new MakeFieldCommand($this->make), ['key' => $key]);
            self::assertSame(Command::FAILURE, $t->getStatusCode(), $t->getDisplay());
        }
        self::assertDirectoryDoesNotExist($this->project . '/src');
        self::assertDirectoryDoesNotExist($this->project . '/public');
    }

    #[Test]
    public function specFieldsUseTheSchemasKeyRuleAndRejectDuplicates(): void
    {
        foreach (['bad.key', 'space key', ''] as $bad) {
            \file_put_contents($this->project . '/keys.json', (string) \json_encode(['key' => 'f2_keys', 'fields' => [['key' => $bad]]]));
            self::assertSame(Command::INVALID, $this->exec($this->collectionCommand(), ['--from' => 'keys.json'])->getStatusCode());
            self::assertDirectoryDoesNotExist($this->project . '/src');
        }
        \file_put_contents($this->project . '/keys.json', (string) \json_encode(['key' => 'f2_keys', 'fields' => [['key' => 'name'], ['key' => 'name']]]));
        self::assertSame(Command::INVALID, $this->exec($this->collectionCommand(), ['--from' => 'keys.json'])->getStatusCode());
        self::assertDirectoryDoesNotExist($this->project . '/src');
        \file_put_contents($this->project . '/keys.json', (string) \json_encode(['key' => 'f2_keys', 'fields' => [['key' => '1-name'], ['key' => 'camelCase']]]));
        $t = $this->exec($this->collectionCommand(), ['--from' => 'keys.json']);
        self::assertSame(Command::SUCCESS, $t->getStatusCode(), $t->getDisplay());
    }
}
