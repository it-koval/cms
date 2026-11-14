<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Admin\NavBuilder;
use Itk\Cms\Extension\Registry;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Schema\SchemaBuilder;
use Itk\Cms\Security\Viewer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The default menu group of collections: a collection with the group «Коллекции» written in any language of the interface joins the default group of the reader. */
final class NavGroupLocaleTest extends KernelTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function readers(): iterable
    {
        yield 'en' => ['en', 'Collections'];
        yield 'ru' => ['ru', 'Коллекции'];
        yield 'kk' => ['kk', 'Коллекциялар'];
    }

    /** @return list<array{string, list<string>}> group → keys of its items */
    private function navGroups(string $locale, string $explicit): array
    {
        self::bootKernel();
        $builder = new SchemaBuilder('ru');
        $builder->collection('t_plain', 'Plain');
        $builder->collection('t_named', 'Named')->menu(group: $explicit);
        $builder->collection('t_other', 'Other')->menu(group: 'Catalog');
        $nav = new NavBuilder($builder->build(), self::getContainer()->get(Registry::class), self::getContainer()->get(RequestService::class));

        $out = [];
        foreach (self::getContainer()->get(AdminLocale::class)->run($locale, static fn (): array => $nav->build(Viewer::system())) as $g) {
            $keys = \array_column($g['items'], 'key');
            if ([] !== \array_filter($keys, static fn (string $k): bool => \str_starts_with($k, 'col:'))) {
                $out[] = [$g['group'], $keys];
            }
        }

        return $out;
    }

    #[Test]
    #[DataProvider('readers')]
    public function theDefaultGroupWrittenInAnyLanguageIsOneGroup(string $locale, string $name): void
    {
        foreach (['Collections', 'Коллекции', 'Коллекциялар'] as $written) {
            $groups = $this->navGroups($locale, $written);
            $byName = \array_column($groups, 1, 0);

            self::assertSame(['col:t_named', 'col:t_plain'], $byName[$name] ?? [], $locale . ' reader, group written as «' . $written . '»');
            self::assertSame(['col:t_other'], $byName['Catalog'] ?? []);
            self::assertCount(2, $groups);
            self::ensureKernelShutdown();
        }
    }
}
