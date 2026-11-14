<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;
use Itk\Cms\Doctrine\MigrationVersionComparator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Порядок миграций: по дате в имени класса, а не по полному имени; сайт и бандл выполняются вперемешку, как они появлялись. */
final class MigrationOrderTest extends KernelTestCase
{
    /** @param list<string> $classes
     * @return list<string> */
    private function sorted(array $classes): array
    {
        $cmp = new MigrationVersionComparator();
        \usort($classes, static fn (string $a, string $b): int => $cmp->compare(new Version($a), new Version($b)));

        return $classes;
    }

    #[Test]
    public function aSiteMigrationRunsAfterTheBundleMigrationThatCreatesItsTable(): void
    {
        $order = $this->sorted([
            'DoctrineMigrations\\Version20261029120000', // reads itk_page_translation
            'Itk\\Cms\\Migrations\\Version20261029000000', // creates it
            'DoctrineMigrations\\Version20261030120000', // reads itk_setting
        ]);

        self::assertSame([
            'Itk\\Cms\\Migrations\\Version20261029000000',
            'DoctrineMigrations\\Version20261029120000',
            'DoctrineMigrations\\Version20261030120000',
        ], $order);
    }

    #[Test]
    public function theDateDecidesAndTheNamespaceBreaksATie(): void
    {
        $order = $this->sorted([
            'Itk\\Cms\\Migrations\\Version20261030120000',
            'DoctrineMigrations\\Version20261030120000',
            'Itk\\Cms\\Migrations\\Version20261030110000',
            'DoctrineMigrations\\Version20261025091935',
            'App\\Other\\Version20270101000000',
        ]);

        self::assertSame([
            'DoctrineMigrations\\Version20261025091935',
            'Itk\\Cms\\Migrations\\Version20261030110000',
            'DoctrineMigrations\\Version20261030120000',
            'Itk\\Cms\\Migrations\\Version20261030120000',
            'App\\Other\\Version20270101000000',
        ], $order);
        self::assertSame(0, (new MigrationVersionComparator())->compare(new Version('A\\Version20261030120000'), new Version('A\\Version20261030120000')));
    }

    #[Test]
    public function aClassNotNamedByDateGoesLastAndDoesNotBreakTheOrder(): void
    {
        self::assertSame('99999999999999', MigrationVersionComparator::stamp('Foo\\Bar'));
        self::assertSame(['A\\Version20260101000000', 'A\\Legacy'], $this->sorted(['A\\Legacy', 'A\\Version20260101000000']));
    }

    #[Test]
    public function theBundleHandsTheComparatorToTheMigrationsLibraryOfEverySite(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');

        self::assertInstanceOf(MigrationVersionComparator::class, $factory->getVersionComparator());
        self::assertInstanceOf(Comparator::class, $factory->getVersionComparator());
    }

    #[Test]
    public function theMigrationsOfTheSiteAndTheBundleAreListedInOneOrder(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');

        $names = \array_values(\array_map(static fn ($m): string => (string) $m->getVersion(), $factory->getMigrationPlanCalculator()->getMigrations()->getItems()));
        self::assertNotEmpty($names);
        $stamps = \array_map(static fn (string $n): string => MigrationVersionComparator::stamp($n), $names);
        $sorted = $stamps;
        \sort($sorted);
        self::assertSame($sorted, $stamps, 'the list follows the dates in the class names: ' . \implode(', ', $names));
        $bundle = \array_search('Itk\\Cms\\Migrations\\Version20261029000000', $names, true);
        $site = \array_search('DoctrineMigrations\\Version20261029120000', $names, true);
        if (false !== $bundle && false !== $site) {
            self::assertLessThan($site, $bundle, 'the table is created before the site migration reads it');
        }
    }
}
