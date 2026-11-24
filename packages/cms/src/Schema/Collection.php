<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Field\FormFields;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Resource\Action;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\Metric;
use Itk\Cms\Security\Viewer;

/**
 * A collection: a list of records WITHOUT pages of their own.
 * Mirrors admin/src/schema/collections.js.
 */
final class Collection
{
    private string $singular;
    private ?string $newLabel = null;
    private string $icon = 'list';
    private string $description = '';
    private string $view = 'table';
    private ?string $titleField = null;
    private ?string $thumbField = null;
    private ?string $subField = null;
    private ?string $toggleField = null;
    private bool $sortable = false;
    private ?string $singleton = null;
    /** @var array<string, mixed>|null */
    private ?array $matrix = null;
    /** @var list<array{key: string, label: string|null, fields: list<FieldInterface>}> the label of the first tab is null: «Содержимое» in the language of the moment */
    private array $tabs = [];
    /** @var array{title: string, text: string}|null null: the usual words in the language of the moment */
    private ?array $empty = null;
    /** @var list<array<string, mixed>> */
    private array $views = [];
    /** @var list<FieldInterface> */
    private array $filters = [];
    /** @var list<Action> */
    private array $actions = [];
    /** @var list<Metric> */
    private array $metrics = [];
    private bool $tree = false;
    /** @var array<string, 'asc'|'desc'> */
    private array $defaultSort = [];
    private int $perPage = 50;
    private ?\Closure $canSee = null;
    /** @var array{group: ?string, groupIcon: ?string, groupOrder: ?int, icon: ?string, order: ?int, hidden: bool, canSee: ?string} */
    private array $menu = ['group' => null, 'groupIcon' => null, 'groupOrder' => null, 'icon' => null, 'order' => null, 'hidden' => false, 'canSee' => null];
    private ?CollectionResource $resource = null;

    public function __construct(private readonly string $key, private readonly string $title)
    {
        $this->singular = \mb_strtolower($title);
    }

    public function singular(string $singular): self
    {
        $this->singular = $singular;

        return $this;
    }

    public function newLabel(string $label): self
    {
        $this->newLabel = $label;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** 'table' | 'cards' | 'matrix' */
    public function view(string $view): self
    {
        $this->view = $view;

        return $this;
    }

    /**
     * The views of the list: 'table', 'cards', 'calendar' => ['start' => 'dateFrom', 'end' => 'dateTo', 'title' => 'title'], 'kanban' => ['field' => 'status', 'columns' => [...]].
     *
     * @param array<int|string, mixed> $views
     */
    public function views(array $views): self
    {
        $out = [];
        foreach ($views as $k => $v) {
            $type = \is_int($k) ? (string) $v : (string) $k;
            if (!\in_array($type, ['table', 'cards', 'calendar', 'kanban'], true)) {
                throw new \InvalidArgumentException(\sprintf('The view "%s" of the collection "%s" does not exist: use table, cards, calendar or kanban.', $type, $this->key));
            }
            $out[] = ['type' => $type] + (\is_int($k) ? [] : (array) $v);
        }
        $this->views = $out;
        if (isset($out[0]) && \in_array($out[0]['type'], ['table', 'cards'], true)) {
            $this->view = $out[0]['type'];
        }

        return $this;
    }

    /**
     * The views name fields: the calendar its start (and end), the kanban the select it is cut by. Called when the schema is built (the fields are all known then).
     *
     * @throws \InvalidArgumentException
     */
    public function checkViews(): void
    {
        foreach ($this->views as $v) {
            $at = \sprintf('The view "%s" of the collection "%s"', $v['type'], $this->key);
            if ('calendar' === $v['type']) {
                foreach (['start' => true, 'end' => false] as $role => $required) {
                    $key = $v[$role] ?? null;
                    if (null === $key && $required) {
                        throw new \InvalidArgumentException(\sprintf('%s needs "start": the key of the date field the record stands on.', $at));
                    }
                    if (null !== $key && null === $this->field((string) $key)) {
                        throw new \InvalidArgumentException(\sprintf('%s: there is no field "%s" for "%s".', $at, $key, $role));
                    }
                }
            }
            if ('kanban' === $v['type']) {
                $f = isset($v['field']) ? $this->field((string) $v['field']) : null;
                if (null === $f || 'select' !== $f->type()) {
                    throw new \InvalidArgumentException(\sprintf('%s needs "field": the key of a select field (columns are its values).', $at));
                }
            }
        }
    }

    /**
     * The views as the admin reads them: the calendar has a title field, the kanban its columns as [value, label] pairs (by default: the options of the select).
     *
     * @return list<array<string, mixed>>
     */
    private function viewList(): array
    {
        if ([] === $this->views) {
            return [['type' => $this->view]];
        }
        $out = [];
        foreach ($this->views as $v) {
            if ('calendar' === $v['type']) {
                $v += ['end' => null, 'title' => $this->titleFieldKey()];
            }
            if ('kanban' === $v['type']) {
                $columns = [];
                foreach ((array) ($v['columns'] ?? $this->field((string) $v['field'])?->choices() ?? []) as $value => $item) {
                    $columns[] = \is_array($item) ? [(string) $item[0], (string) ($item[1] ?? $item[0])] : [(string) (\is_int($value) ? $item : $value), (string) $item];
                }
                $v['columns'] = $columns;
            }
            $out[] = $v;
        }

        return $out;
    }

    /** The filters of the list (default: the fields marked filterable()). */
    public function filters(FieldInterface ...$fields): self
    {
        $this->filters = \array_values($fields);

        return $this;
    }

    public function actions(Action ...$actions): self
    {
        $this->actions = \array_values($actions);

        return $this;
    }

    public function metrics(Metric ...$metrics): self
    {
        $this->metrics = \array_values($metrics);

        return $this;
    }

    /** Records nest into each other. */
    public function tree(bool $tree = true): self
    {
        $this->tree = $tree;

        return $this;
    }

    /**
     * The order of the list when the person chose none: ['date' => 'desc'], or ['-date', 'title'].
     *
     * @param array<int|string, string> $sort
     */
    public function defaultSort(array $sort): self
    {
        $out = [];
        foreach ($sort as $k => $v) {
            if (\is_int($k)) {
                $desc = \str_starts_with((string) $v, '-');
                $out[\ltrim((string) $v, '-')] = $desc ? 'desc' : 'asc';
            } else {
                $out[(string) $k] = 'desc' === \strtolower((string) $v) ? 'desc' : 'asc';
            }
        }
        $this->defaultSort = $out;

        return $this;
    }

    public function perPage(int $perPage): self
    {
        $this->perPage = \max(1, \min(200, $perPage));

        return $this;
    }

    /** Is the collection shown to this person at all? */
    public function visibleWhen(\Closure $canSee): self
    {
        $this->canSee = $canSee;

        return $this;
    }

    /** Place in the admin menu (a resource class says the same with #[Group] #[Order] #[Icon] #[CanSee] #[HideFromMenu]). */
    public function menu(?string $group = null, ?int $order = null, ?string $icon = null, bool $hidden = false, ?string $canSee = null, ?string $groupIcon = null, ?int $groupOrder = null): self
    {
        $this->menu = ['group' => $group, 'groupIcon' => $groupIcon, 'groupOrder' => $groupOrder, 'icon' => $icon, 'order' => $order, 'hidden' => $hidden, 'canSee' => $canSee];

        return $this;
    }

    /** The class that described the collection (hooks are called on it). */
    public function bind(?CollectionResource $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function titleField(string $key): self
    {
        $this->titleField = $key;

        return $this;
    }

    public function thumbField(?string $key): self
    {
        $this->thumbField = $key;

        return $this;
    }

    public function subField(?string $key): self
    {
        $this->subField = $key;

        return $this;
    }

    public function toggleField(?string $key): self
    {
        $this->toggleField = $key;

        return $this;
    }

    /** The order of the list is the order on the site and the staff can change it. */
    public function sortable(bool $sortable = true): self
    {
        $this->sortable = $sortable;

        return $this;
    }

    /** One record only, with this id (the tariff matrix: 'main'). */
    public function singleton(string $id): self
    {
        $this->singleton = $id;

        return $this;
    }

    /**
     * @param array<string, mixed> $matrix { colFields, rowKinds, rowLabel } for view('matrix')
     */
    public function matrix(array $matrix): self
    {
        $this->matrix = $matrix;
        $this->view = 'matrix';

        return $this;
    }

    /** The only tab «Содержимое». */
    public function fields(FieldInterface ...$fields): self
    {
        $this->tabs = [['key' => 'content', 'label' => null, 'fields' => \array_values($fields)]];

        return $this;
    }

    public function tab(string $key, string $label, FieldInterface ...$fields): self
    {
        $this->tabs[] = ['key' => $key, 'label' => $label, 'fields' => \array_values($fields)];

        return $this;
    }

    public function emptyState(string $title, string $text): self
    {
        $this->empty = ['title' => $title, 'text' => $text];

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function viewName(): string
    {
        return $this->view;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function resource(): ?CollectionResource
    {
        return $this->resource;
    }

    /** @return array{group: ?string, groupIcon: ?string, groupOrder: ?int, icon: ?string, order: ?int, hidden: bool, canSee: ?string} */
    public function menuRule(): array
    {
        return $this->menu;
    }

    public function iconName(): string
    {
        return $this->icon;
    }

    public function isVisibleTo(Viewer $viewer): bool
    {
        if (null !== $this->canSee && !($this->canSee)($viewer)) {
            return false;
        }

        return $this->resource?->canSee($viewer) ?? true;
    }

    public function isTree(): bool
    {
        return $this->tree;
    }

    public function perPageCount(): int
    {
        return $this->perPage;
    }

    /** @return array<string, 'asc'|'desc'> */
    public function defaultSortRule(): array
    {
        return $this->defaultSort;
    }

    /** @return list<Metric> */
    public function metricList(): array
    {
        return $this->metrics;
    }

    /** @return list<Action> */
    public function customActions(): array
    {
        return $this->actions;
    }

    /**
     * The actions of the collection: its own, then the ones every collection has («Опубликовать выбранные», «Снять с публикации», «Удалить выбранные»)
     * unless the collection has an action with the same key.
     *
     * @return list<Action>
     */
    public function actionList(): array
    {
        $out = $this->actions;
        if (null === $this->singleton && 'matrix' !== $this->view) {
            $have = \array_map(static fn (Action $a): string => $a->key(), $out);
            foreach ([
                Action::builtin(I18n::t('model.action_publish_selected'), 'publish-selected', 'publish'),
                Action::builtin(I18n::t('model.action_unpublish_selected'), 'unpublish-selected', 'publish'),
                Action::builtin(I18n::t('model.action_delete_selected'), 'delete-selected', 'delete', true, I18n::t('model.action_delete_confirm')),
            ] as $builtin) {
                if (!\in_array($builtin->key(), $have, true)) {
                    $out[] = $builtin;
                }
            }
        }

        return $out;
    }

    /**
     * Can this person run the action: it is not hidden from him, he has the right on the collection it needs and, for an action with
     * permission(), the line of the role matrix `action:<collection>:<action>`.
     */
    public function mayRun(Action $action, Viewer $viewer): bool
    {
        return $action->visibleTo($viewer)
            && $viewer->can('col:' . $this->key, $action->right())
            && (!$action->hasPermission() || $viewer->can('action:' . $this->key . ':' . $action->key()));
    }

    /** @return list<Action> */
    public function actionsFor(Viewer $viewer): array
    {
        return \array_values(\array_filter($this->actionList(), fn (Action $a): bool => $this->mayRun($a, $viewer)));
    }

    public function action(string $key): ?Action
    {
        foreach ($this->actionList() as $a) {
            if ($a->key() === $key) {
                return $a;
            }
        }

        return null;
    }

    /**
     * The fields the list can be filtered by (explicit filters(), else the fields marked filterable()).
     *
     * @return list<FieldInterface>
     */
    public function filterFields(): array
    {
        if ([] !== $this->filters) {
            foreach ($this->filters as $f) {
                if (null === $f->filterKind() && \method_exists($f, 'filterable')) {
                    $f->filterable();
                }
            }

            return $this->filters;
        }

        return \array_values(\array_filter($this->fieldList(), static fn (FieldInterface $f): bool => null !== $f->filterKind()));
    }

    /** @return list<string> keys of the fields the search box looks in (marked searchable(), else the title and the sub line) */
    public function searchKeys(): array
    {
        $keys = [];
        foreach ($this->fieldList() as $f) {
            if ($f->isSearchable()) {
                $keys[] = $f->key();
            }
        }
        if ([] === $keys) {
            $keys[] = $this->titleFieldKey();
            if (null !== $this->subField) {
                $keys[] = $this->subField;
            }
        }

        return $keys;
    }

    public function singletonId(): ?string
    {
        return $this->singleton;
    }

    public function subFieldKey(): ?string
    {
        return $this->subField;
    }

    /** The title field the collection named itself (titleField()); null when the first text field stands for it. */
    public function declaredTitleField(): ?string
    {
        return $this->titleField;
    }

    /** @return list<array{key: string, label: string, fields: list<FieldInterface>}> */
    public function tabList(): array
    {
        return \array_map(static fn (array $t): array => ['key' => $t['key'], 'label' => $t['label'] ?? I18n::t('model.collection_tab_content'), 'fields' => $t['fields']], $this->tabs);
    }

    public function titleFieldKey(): string
    {
        if (null !== $this->titleField) {
            return $this->titleField;
        }
        foreach ($this->fieldList() as $f) {
            if ('text' === $f->type()) {
                return $f->key();
            }
        }

        return $this->fieldList()[0]?->key() ?? 'title';
    }

    /** @return list<FieldInterface> every editable field of every tab */
    public function fieldList(): array
    {
        $out = [];
        foreach ($this->tabs as $tab) {
            foreach ($tab['fields'] as $f) {
                if (!$f->isNote()) {
                    $out[] = $f;
                }
            }
        }

        return $out;
    }

    public function field(string $key): ?FieldInterface
    {
        foreach ($this->fieldList() as $f) {
            if ($f->key() === $key) {
                return $f;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed> the collection as the admin reads it; with a viewer: only the fields and actions he may use
     */
    public function toArray(?Viewer $viewer = null): array
    {
        $titleField = $this->titleFieldKey();
        $show = static fn (array $fields): array => null === $viewer ? $fields : \array_values(\array_filter($fields, static fn (FieldInterface $f): bool => $f->visibleTo($viewer)));
        $out = [
            'key' => $this->key,
            'title' => $this->title,
            'singular' => $this->singular,
            'newLabel' => $this->newLabel ?? I18n::t('model.collection_new'),
            'icon' => $this->icon,
            'description' => $this->description,
            'view' => $this->view,
            'titleField' => $titleField,
            'thumbField' => $this->thumbField,
            'subField' => $this->subField,
            'sortable' => $this->sortable,
            'tabs' => \array_map(static fn (array $t): array => [
                'key' => $t['key'],
                'label' => $t['label'],
                'fields' => FormFields::toArray($t['fields'], $viewer),
            ], $this->tabList()),
            'empty' => $this->empty ?? ['title' => I18n::t('model.collection_empty_title'), 'text' => I18n::t('model.collection_empty_text')],
            'views' => $this->viewList(),
            'filters' => \array_map(static fn (FieldInterface $f): array => $f->toArray(), $show($this->filterFields())),
            'searchKeys' => $this->searchKeys(),
            'metrics' => \array_map(static fn (Metric $m): array => ['label' => $m->label, 'kind' => $m->kind], $this->metrics),
            'actions' => \array_map(static fn (Action $a): array => $a->toArray(), null === $viewer ? $this->actionList() : $this->actionsFor($viewer)),
            'tree' => $this->tree,
            'defaultSort' => $this->defaultSort,
            'perPage' => $this->perPage,
        ];
        if (null !== $this->toggleField) {
            $out['toggleField'] = $this->toggleField;
        }
        if (null !== $this->singleton) {
            $out['singleton'] = $this->singleton;
        }
        if (null !== $this->matrix) {
            $out['matrix'] = $this->matrix;
        }

        return $out;
    }
}
