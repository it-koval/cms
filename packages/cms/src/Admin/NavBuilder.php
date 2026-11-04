<?php

declare(strict_types=1);

namespace Itk\Cms\Admin;

use Itk\Cms\Extension\Registry;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\RequestService;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Viewer;

/**
 * The menu of the admin, built by the server for one person: only what he may open.
 *
 *   nav: [{ key, group, icon, order, items: [{ key, label, to, icon, order, badge?, sub? }] }]
 *
 * `group` is the heading ('' = none), groups and items come sorted. The built-in items (Сегодня, Страницы + quick links of templates, Заявки,
 * Медиатека, Настройки) are items like the others, with their own `order`; every collection class / definition adds its item
 * (group: #[Group] / menu(), default «Коллекции»), and so do the NavItem of extensions.
 *
 * @internal
 */
final class NavBuilder
{
    public function __construct(
        private readonly Schema $schema,
        private readonly Registry $extensions,
        private readonly RequestService $requests,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function build(Viewer $viewer): array
    {
        /** @var array<string, array{key: string, group: string, icon: ?string, order: int, items: list<array<string, mixed>>}> $groups */
        $groups = [];
        $add = static function (string $key, string $label, ?string $icon, int $order, array $item) use (&$groups): void {
            $groups[$key] ??= ['key' => $key, 'group' => $label, 'icon' => $icon, 'order' => $order, 'items' => []];
            $groups[$key]['order'] = \min($groups[$key]['order'], $order);
            $groups[$key]['icon'] ??= $icon;
            $groups[$key]['items'][] = $item;
        };

        $add('main', '', null, 0, ['key' => 'today', 'label' => I18n::t('http.nav_today'), 'to' => '/', 'icon' => 'today', 'order' => 0]);
        if ($viewer->can('pages')) {
            $add('main', '', null, 0, ['key' => 'pages', 'label' => I18n::t('http.nav_pages'), 'to' => '/pages', 'icon' => 'pages', 'order' => 10]);
            $n = 0;
            foreach ($this->schema->templates() as $t) {
                if (null !== $t->shortcutLabel() && $viewer->can('pages', 'view', $t->key())) {
                    $add('main', '', null, 0, ['key' => 'pages:' . $t->key(), 'label' => $t->shortcutLabel(), 'to' => '/pages?template=' . $t->key(), 'icon' => 'pages', 'order' => 11 + $n++, 'sub' => true]);
                }
            }
        }
        if ($viewer->can('requests')) {
            $item = ['key' => 'requests', 'label' => I18n::t('http.nav_requests'), 'to' => '/requests', 'icon' => 'inbox', 'order' => 20];
            if (($new = $this->requests->counts()['new']) > 0) {
                $item['badge'] = $new;
            }
            $add('main', '', null, 0, $item);
        }
        if ($viewer->can('media')) {
            $add('main', '', null, 0, ['key' => 'media', 'label' => I18n::t('http.nav_media'), 'to' => '/media', 'icon' => 'image', 'order' => 30]);
        }

        $default = I18n::t('http.nav_collections');
        $defaultNames = I18n::variants('http.nav_collections');
        foreach ($this->schema->collections() as $c) {
            $menu = $c->menuRule();
            if ($menu['hidden'] || !$viewer->can('col:' . $c->key()) || !$c->isVisibleTo($viewer) || (null !== $menu['canSee'] && !$viewer->allows($menu['canSee']))) {
                continue;
            }
            // the default group written in any language of the interface is the default group of the reader (as SectionType::group does)
            $label = null === $menu['group'] || \in_array($menu['group'], $defaultNames, true) ? $default : $menu['group'];
            $add('g:' . $label, $label, $menu['groupIcon'] ?? ($default === $label ? 'layers' : null), $menu['groupOrder'] ?? 50, [
                'key' => 'col:' . $c->key(), 'label' => $c->title(), 'to' => '/collections/' . $c->key(), 'icon' => $menu['icon'] ?? $c->iconName(), 'order' => $menu['order'] ?? 100,
            ]);
        }

        foreach ($this->extensions->menuItems() as $n) {
            if ($n->visibleTo($viewer)) {
                $add('g:' . $n->groupName(), $n->groupName(), $n->groupIcon(), $n->groupOrder(), [
                    'key' => 'x:' . $n->to(), 'label' => $n->label(), 'to' => $n->to(), 'icon' => $n->iconName(), 'order' => $n->position(),
                ]);
            }
        }

        if ($viewer->can('dev')) {
            $group = I18n::t('http.nav_developer');
            $add('g:' . $group, $group, 'monitor', 850, ['key' => 'health', 'label' => I18n::t('http.nav_health'), 'to' => '/health', 'icon' => 'heart', 'order' => 10]);
        }

        if ($this->anySettings($viewer)) {
            $add('settings', '', null, 900, ['key' => 'settings', 'label' => I18n::t('http.nav_settings'), 'to' => '/settings', 'icon' => 'sliders', 'order' => 0]);
        }

        $out = \array_values($groups);
        \usort($out, static fn (array $a, array $b): int => [$a['order'], $a['group']] <=> [$b['order'], $b['group']]);
        foreach ($out as &$g) {
            \usort($g['items'], static fn (array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);
        }

        return $out;
    }

    private function anySettings(Viewer $viewer): bool
    {
        foreach ($this->schema->permissionAreas() as $a) {
            if (('settings' === $a['group']) && $viewer->can($a['key'])) {
                return true;
            }
        }

        return false;
    }
}
