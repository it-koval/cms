<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Settings\Settings;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Twig\Environment;

/** Настройки для кода (Itk\Cms\Settings\Settings): значение приводится к типу поля, Twig itk_settings() — то же самое. */
final class SettingsApiTest extends ResourceKernelTestCase
{
    private Settings $settings;
    private SettingsService $store;

    protected function prepare(): void
    {
        self::getContainer()->get(\Itk\Cms\Resource\ResourceRegistry::class)->add(new class extends \Itk\Cms\Resource\SettingsResource {
            public function key(): string
            {
                return 'test_implicit_settings';
            }

            public function title(): string
            {
                return 'Implicit defaults';
            }

            public function fields(): iterable
            {
                yield \Itk\Cms\Field\Select::make('Mode', 'testImplicitMode')->options(['modal' => 'Modal', 'anchor' => 'Anchor']);
            }
        });
        parent::prepare();
        $this->settings = self::getContainer()->get(Settings::class);
        $this->store = self::getContainer()->get(SettingsService::class);
    }

    #[Test]
    public function aValueIsCastToTheTypeOfItsField(): void
    {
        // raw values as an import or a hand-edited row may leave them
        $this->store->set('testOn', '1');
        $this->store->set('testSeats', '12');
        $this->store->set('testTitle', 42, 'ru');

        self::assertTrue($this->settings->get('testOn'));
        self::assertSame(12, $this->settings->get('testSeats'));
        self::assertSame('42', $this->settings->get('testTitle'));
        self::assertTrue($this->settings->bool('testOn'));
        self::assertSame(12, $this->settings->int('testSeats'));
        self::assertSame('42', $this->settings->string('testTitle'));
    }

    #[Test]
    public function aDecimalNumberStaysDecimal(): void
    {
        $this->store->set('testSeats', '2.5');
        self::assertSame(2.5, $this->settings->get('testSeats'));
        self::assertSame(2, $this->settings->int('testSeats'));
    }

    #[Test]
    public function aDefaultIsUsedForAnEmptyValueButFalseAndZeroAreRealValues(): void
    {
        self::assertSame('запас', $this->settings->string('testTitle', 'запас'));
        self::assertSame('запас', $this->settings->get('testTitle', 'запас'));
        self::assertSame(7, $this->settings->int('testSeats', 7));
        self::assertNull($this->settings->get('testSeats'));
        self::assertTrue($this->settings->bool('testOn', true), 'never set: the default');
        self::assertSame('x', $this->settings->get('no_such_setting', 'x'));
        self::assertSame(['a'], $this->settings->array('testList', ['a']));

        $this->store->set('testOn', false);
        $this->store->set('testSeats', 0);
        self::assertFalse($this->settings->get('testOn'));
        self::assertFalse($this->settings->bool('testOn', true), 'a saved false beats the default');
        self::assertSame(0, $this->settings->int('testSeats', 7));
        self::assertFalse($this->settings->all()['testOn']);
        self::assertSame(0, $this->settings->all()['testSeats']);
    }

    #[Test]
    public function anUnsavedSettingReadsAsTheInitialValueOfItsField(): void
    {
        self::assertSame('modal', $this->settings->get('requestButtonMode'));
        self::assertSame('modal', $this->settings->string('requestButtonMode', 'anchor'));
        $this->store->set('requestButtonMode', 'anchor');
        try {
            self::assertSame('anchor', $this->settings->string('requestButtonMode'));
        } finally {
            $this->store->update(['requestButtonMode' => 'modal']);
            self::getContainer()->get('doctrine.orm.entity_manager')->createQuery('DELETE FROM Itk\Cms\Entity\Setting s WHERE s.key = :k')->setParameter('k', 'requestButtonMode')->execute();
        }
    }

    #[Test]
    public function aPictureAndAListHaveTheirOwnGetters(): void
    {
        self::assertNull($this->settings->media('testLogo'));

        $this->store->set('testLogo', ['src' => '/uploads/media/logo.png', 'alt' => 'Лого']);
        self::assertSame(['src' => '/uploads/media/logo.png', 'alt' => 'Лого'], $this->settings->media('testLogo'));
        self::assertSame(['src' => '/uploads/media/logo.png', 'alt' => 'Лого'], $this->settings->get('testLogo'));

        $this->store->set('testLogo', ['src' => '', 'alt' => 'x']);
        self::assertNull($this->settings->media('testLogo'));
        self::assertSame('запас', $this->settings->get('testLogo', 'запас'));

        $this->store->set('testList', [['_id' => 'p1', 'item' => 'A'], ['_id' => 'p2', 'item' => 'B']]);
        self::assertSame(['A', 'B'], \array_column($this->settings->array('testList'), 'item'));
    }

    #[Test]
    public function aTranslatableSettingIsReadInTheAskedLanguage(): void
    {
        $this->store->set('testTitle', 'Привет', 'ru');
        $this->store->set('testTitle', 'Hello', 'en');

        self::assertSame('Привет', $this->settings->string('testTitle'));
        self::assertSame('Hello', $this->settings->string('testTitle', locale: 'en'));
        self::assertSame('Hello', $this->settings->get('testTitle', null, 'en'));
    }

    #[Test]
    public function allReturnsEveryCastValue(): void
    {
        $this->store->set('testOn', 1);
        $all = $this->settings->all();
        self::assertTrue($all['testOn']);
        self::assertSame('modal', $all['testImplicitMode']);
        self::assertSame('modal', $this->settings->get('testImplicitMode'));
        self::assertSame('modal', $this->settings->string('testImplicitMode'));
        self::assertArrayHasKey('siteName', $all);
        self::assertArrayHasKey('tgOn', $all, 'the core keys stay');
    }

    #[Test]
    public function twigUsesTheSameService(): void
    {
        $this->store->set('testOn', '1');
        $this->store->set('testSeats', '5');
        $twig = self::getContainer()->get(Environment::class);

        self::assertSame('yes|6|запас', $twig->createTemplate("{{ itk_settings('testOn') ? 'yes' : 'no' }}|{{ itk_settings('testSeats') + 1 }}|{{ itk_settings('testTitle', 'запас') }}")->render());
        self::assertSame('Site', $twig->createTemplate("{{ itk_settings('nope', 'Site') }}")->render());
        self::assertSame('1', $twig->createTemplate('{{ itk_settings().testOn ? 1 : 0 }}')->render());
    }
}
