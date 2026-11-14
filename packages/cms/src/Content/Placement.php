<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\Template;

/**
 * Where a template may stand and whether a page may get another template. Mirrors canPlace / canChangeTemplate of admin/src/schema/templates.js.
 * Page rows are { id, parentId, template, title }.
 *
 * @internal
 */
final class Placement
{
    public function __construct(private readonly Schema $schema)
    {
    }

    public function topLevelAllowed(Template $t): bool
    {
        return null === $t->parentRule() || 'any' === $t->parentRule();
    }

    /** May a page with template `t` stand directly under a page with template `parentKey`? A leaf template is nobody's parent. */
    public function parentFits(Template $t, string $parentKey): bool
    {
        $p = $this->schema->template($parentKey);
        if (null !== $p && $p->isLeaf()) {
            return false;
        }
        $rule = $t->parentRule();

        return 'any' === $rule || (\is_array($rule) && \in_array($parentKey, $rule, true));
    }

    /**
     * @param array{id: string, template: string}|null $parent a page row or null for the top level
     * @param list<array<string, mixed>> $pages
     *
     * @return array{ok: bool, reason: string}
     */
    public function canPlace(string $key, ?array $parent, array $pages = [], ?string $exceptId = null): array
    {
        $t = $this->schema->template($key);
        if (null === $t) {
            return ['ok' => false, 'reason' => I18n::t('content.placement_template_unknown')];
        }
        if ($t->isSingle()) {
            foreach ($pages as $p) {
                if ($p['id'] !== $exceptId && $p['template'] === $key) {
                    return ['ok' => false, 'reason' => 'home' === $key ? I18n::t('content.placement_home_exists') : I18n::t('content.placement_single', ['template' => $t->title()])];
                }
            }
        }
        if (null === $parent) {
            if ($this->topLevelAllowed($t)) {
                return ['ok' => true, 'reason' => ''];
            }

            return ['ok' => false, 'reason' => I18n::t('content.placement_only_inside', ['template' => $t->title(), 'parents' => $this->orList((array) $t->parentRule())])];
        }
        if (null === $t->parentRule()) {
            return ['ok' => false, 'reason' => $t->isRoot() ? I18n::t('content.placement_home_top') : I18n::t('content.placement_top_only', ['template' => $t->title()])];
        }
        if ($this->parentFits($t, (string) $parent['template'])) {
            return ['ok' => true, 'reason' => ''];
        }

        return ['ok' => false, 'reason' => I18n::t('content.placement_only_inside', ['template' => $t->title(), 'parents' => $this->orList((array) $t->parentRule())])];
    }

    /**
     * Can the page get template `key`? `lost` = labels of the page's fields the new template does not have.
     *
     * @param array<string, mixed> $page row { id, parentId, template }
     * @param list<array<string, mixed>> $pages
     *
     * @return array{ok: bool, reason: string, lost: list<string>}
     */
    public function canChangeTemplate(array $page, string $key, array $pages): array
    {
        $cur = $this->schema->template((string) $page['template']);
        $next = $this->schema->template($key);
        $lost = [];
        if (null !== $cur && null !== $next) {
            $keep = \array_map(static fn ($f): string => $f->key(), $next->fieldList());
            foreach ($cur->fieldList() as $f) {
                if (!\in_array($f->key(), $keep, true)) {
                    $lost[] = $f->label();
                }
            }
        }
        if (null === $next) {
            return ['ok' => false, 'reason' => I18n::t('content.placement_template_unknown'), 'lost' => $lost];
        }
        if ($key === $page['template']) {
            return ['ok' => true, 'reason' => '', 'lost' => []];
        }
        if (null !== $cur && $cur->isRoot()) {
            return ['ok' => false, 'reason' => I18n::t('content.page_home_template_locked'), 'lost' => $lost];
        }
        $parent = null;
        foreach ($pages as $p) {
            if ($p['id'] === ($page['parentId'] ?? null)) {
                $parent = $p;
                break;
            }
        }
        $here = $this->canPlace($key, $parent, $pages, (string) $page['id']);
        if (!$here['ok']) {
            return $here + ['lost' => $lost];
        }
        foreach ($pages as $c) {
            if (($c['parentId'] ?? null) === $page['id']) {
                $ct = $this->schema->template((string) $c['template']);
                if (null !== $ct && !$this->parentFits($ct, $key)) {
                    return ['ok' => false, 'reason' => I18n::t('content.placement_child_conflict', ['child' => $c['title'] ?? '', 'child_template' => $ct->title(), 'template' => $next->title()]), 'lost' => $lost];
                }
            }
        }

        return ['ok' => true, 'reason' => '', 'lost' => $lost];
    }

    /** @param list<string> $keys */
    private function orList(array $keys): string
    {
        return \implode(I18n::t('content.placement_or'), \array_map(fn (string $k): string => I18n::t('content.placement_quoted', ['text' => $this->schema->template($k)?->title() ?? $k]), $keys));
    }
}
