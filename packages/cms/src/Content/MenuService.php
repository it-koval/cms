<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\MenuArea;
use Itk\Cms\Entity\Revision;
use Itk\Cms\History\HistoryService;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\MenuAreaRepository;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Support\Json;
use Itk\Cms\Support\Slug;
use Itk\Cms\Support\Url;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Menu areas and their items. An area is a place a site template shows with {{ menu('key') }}; the items are per language.
 * Item: { _id, label, link: { kind: 'page'|'url'|'modal', page, url }, children?: [] }; a page link holds the ADDRESS of the page.
 */
final class MenuService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MenuAreaRepository $areas,
        private readonly Schema $schema,
        private readonly LiveUrlIndex $liveUrls,
        private readonly HistoryService $history,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    /** Creates the areas declared in the schema that the database does not have yet. @return int number created */
    public function syncSchemaAreas(): int
    {
        $n = 0;
        foreach ($this->schema->menuAreas() as $def) {
            if (null === $this->areas->find($def->key)) {
                $this->em->persist(new MenuArea($def->key, $def->name, $def->depth));
                ++$n;
            }
        }
        $this->em->flush();

        return $n;
    }

    /** @return list<array<string, mixed>> [{ key, name, depth, snippet, usedIn, usedInAll, counts }] */
    public function areas(): array
    {
        $out = [];
        foreach ($this->areas->findBy([], ['name' => 'ASC']) as $a) {
            $usage = $this->usage($a->key);
            $counts = [];
            foreach ($a->items as $loc => $items) {
                $counts[$loc] = \count($items);
            }
            $out[] = [
                'key' => $a->key, 'name' => $a->name, 'depth' => $a->depth,
                'snippet' => "{{ menu('{$a->key}') }}",
                'usedIn' => $usage['templates'], 'usedInAll' => $usage['all'],
                'counts' => $counts + [$this->mainLocale => 0],
            ];
        }

        return $out;
    }

    /** @return array{templates: list<array{key: string, name: string}>, all: bool} */
    public function usage(string $key): array
    {
        $layout = $this->schema->layout();
        $used = [];
        foreach ($this->schema->templates() as $t) {
            $rule = $t->areasRule();
            $l = 'layout' === $rule ? $layout : $rule;
            if (\in_array($key, [...($l['top'] ?? []), ...($l['bottom'] ?? [])], true)) {
                $used[] = ['key' => $t->key(), 'name' => $t->title()];
            }
        }

        return ['templates' => $used, 'all' => [] !== $used && \count($used) === \count($this->schema->templates())];
    }

    public function createArea(string $name, int $depth = 1): MenuArea
    {
        $name = \trim($name);
        if ('' === $name || \mb_strlen($name) > 60) {
            throw ContentException::invalid(I18n::t('content.menu_name_required'));
        }
        $taken = \array_map(static fn (MenuArea $a): string => $a->key, $this->areas->findAll());
        $a = new MenuArea(Slug::areaKey($name, $taken), $name, \max(1, \min(2, $depth)));
        $this->em->persist($a);
        $this->em->flush();

        return $a;
    }

    /** @param array{name?: string, depth?: int} $patch */
    public function updateArea(string $key, array $patch): MenuArea
    {
        $a = $this->area($key);
        if (isset($patch['name'])) {
            $name = \trim((string) $patch['name']);
            if ('' === $name || \mb_strlen($name) > 60) {
                throw ContentException::invalid(I18n::t('content.menu_name_required'));
            }
            $a->name = $name;
        }
        if (isset($patch['depth'])) {
            $depth = \max(1, \min(2, (int) $patch['depth']));
            if (1 === $depth && 2 === $a->depth) {
                $items = $a->items;
                foreach ($items as $loc => $list) {
                    $flat = [];
                    foreach ($list as $it) {
                        $children = $it['children'] ?? [];
                        unset($it['children']);
                        $flat[] = $it;
                        \array_push($flat, ...$children);
                    }
                    $items[$loc] = $flat;
                }
                $a->items = $items;
            }
            $a->depth = $depth;
        }
        $this->em->flush();

        return $a;
    }

    /** @return array{ok: bool, usedIn: list<array{key: string, name: string}>} */
    public function removeArea(string $key): array
    {
        $a = $this->area($key);
        $usage = $this->usage($key);
        $this->em->remove($a);
        $this->em->flush();

        return ['ok' => true, 'usedIn' => $usage['templates']];
    }

    /** @return array<string, list<array<string, mixed>>> { areaKey: items } of one language, one key per area */
    public function menus(?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $out = [];
        foreach ($this->areas->findAll() as $a) {
            $out[$a->key] = $a->items[$locale] ?? [];
        }

        return $out;
    }

    /**
     * Saves the items of a language: { areaKey: items }.
     *
     * @param array<string, mixed> $menus
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function saveMenus(array $menus, ?string $locale = null, string $action = Revision::EDIT): array
    {
        $locale ??= $this->mainLocale;
        foreach ($menus as $key => $items) {
            $a = $this->areas->find((string) $key) ?? throw ContentException::invalid(I18n::t('content.menu_unknown', ['key' => $key]));
            $clean = $this->cleanItems(\is_array($items) ? $items : [], $a->depth, true);
            $all = $a->items;
            $was = $all[$locale] ?? [];
            $all[$locale] = $clean;
            $a->items = $all;
            $this->history->menu($a, $locale, $was, $action);
        }
        $this->em->flush();

        return $this->menus($locale);
    }

    /**
     * The items of an area for the site: only links that lead somewhere live. { label, kind, url, children }.
     *
     * @return list<array{label: string, kind: string, url: ?string, children: list<array<string, mixed>>}>
     */
    public function resolve(string $key, ?string $locale = null): array
    {
        $locale ??= $this->mainLocale;
        $a = $this->areas->find($key);
        if (null === $a) {
            return [];
        }

        return $this->resolveItems($a->items[$locale] ?? [], $locale);
    }

    private function area(string $key): MenuArea
    {
        return $this->areas->find($key) ?? throw ContentException::notFound(I18n::t('content.menu_not_found'));
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function resolveItems(array $items, string $locale): array
    {
        $out = [];
        foreach ($items as $it) {
            $link = (array) ($it['link'] ?? []);
            $kind = (string) ($link['kind'] ?? 'page');
            if ('page' === $kind) {
                $url = (string) ($link['page'] ?? '');
                if ('' === $url || !$this->liveUrls->has($locale, $url)) {
                    continue;
                }
            } elseif ('url' === $kind) {
                $url = (string) ($link['url'] ?? '');
                if ('' === $url) {
                    continue;
                }
            } else {
                $url = null;
            }
            $out[] = [
                'label' => (string) ($it['label'] ?? ''),
                'kind' => $kind,
                'url' => $url,
                'children' => $this->resolveItems((array) ($it['children'] ?? []), $locale),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function cleanItems(array $items, int $depth, bool $top): array
    {
        $out = [];
        foreach (\array_values($items) as $it) {
            if (!\is_array($it)) {
                continue;
            }
            $label = \trim((string) ($it['label'] ?? ''));
            if ('' === $label) {
                throw ContentException::invalid(I18n::t('content.menu_item_name_required'));
            }
            $link = (array) ($it['link'] ?? []);
            $kind = \in_array($link['kind'] ?? '', ['page', 'url', 'modal'], true) ? (string) $link['kind'] : 'page';
            $url = Url::cleanLinkUrl($link['url'] ?? '');
            $row = [
                '_id' => (string) ($it['_id'] ?? Json::nid('mi')),
                'label' => $label,
                'link' => ['kind' => $kind, 'page' => Url::cleanPageRef($link['page'] ?? '/'), 'url' => $url],
            ];
            $children = (array) ($it['children'] ?? []);
            if ([] !== $children) {
                if ($depth < 2 || !$top) {
                    throw ContentException::invalid(I18n::t('content.menu_no_nested'));
                }
                $row['children'] = $this->cleanItems($children, $depth, false);
            }
            $out[] = $row;
        }

        return $out;
    }
}
