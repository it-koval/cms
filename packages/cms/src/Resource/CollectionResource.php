<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Itk\Cms\Security\Viewer;

/**
 * A collection: a list of records WITHOUT pages of their own (ambassadors, tours, tariffs, reviews).
 * Required: key(), title(), fields(). Everything else is optional. Menu: attributes #[Group] #[Order] #[Icon] #[CanSee] #[HideFromMenu].
 *
 *   #[Group('Каталог', icon: 'box', order: 20)]
 *   final class ToursCollection extends CollectionResource
 *   {
 *       public function key(): string { return 'tours'; }
 *       public function title(): string { return 'Поездки'; }
 *       public function fields(): iterable { yield Text::make('Название', 'title')->required()->searchable()->sortable(); }
 *   }
 */
abstract class CollectionResource implements ResourceInterface
{
    use ResourceHooks;

    /** «поездка»; by default the title in lower case. */
    public function singular(): ?string
    {
        return null;
    }

    public function newLabel(): ?string
    {
        return null;
    }

    public function icon(): ?string
    {
        return null;
    }

    public function description(): string
    {
        return '';
    }

    /**
     * What an empty list says: ['title' => 'Поездок пока нет', 'text' => 'Добавьте первую поездку: город и даты.'] (null = the usual words).
     *
     * @return array{title: string, text: string}|null
     */
    public function emptyState(): ?array
    {
        return null;
    }

    /**
     * The views of the list: ['table', 'cards', 'calendar' => ['start' => 'dateFrom', 'end' => 'dateTo', 'title' => 'title'], 'kanban' => ['field' => 'status', 'columns' => [...]]].
     *
     * @return array<int|string, mixed>
     */
    public function views(): array
    {
        return [];
    }

    public function titleField(): ?string
    {
        return null;
    }

    public function thumbField(): ?string
    {
        return null;
    }

    /** The second line of a card / row. */
    public function subField(): ?string
    {
        return null;
    }

    /** A toggle field that is switched right in the list (the «показывать» switch). */
    public function toggleField(): ?string
    {
        return null;
    }

    /**
     * The filters of the list. Empty = the fields marked filterable().
     *
     * @return iterable<\Itk\Cms\Field\FieldInterface>
     */
    public function filters(): iterable
    {
        return [];
    }

    /** @return iterable<Action> */
    public function actions(): iterable
    {
        return [];
    }

    /** @return iterable<Metric> */
    public function metrics(): iterable
    {
        return [];
    }

    /** Records nest into each other (needs the tree support of the admin). */
    public function tree(): bool
    {
        return false;
    }

    /** The staff set the order of the records by hand. */
    public function sortable(): bool
    {
        return false;
    }

    /**
     * The order of the list when the person did not choose one: ['date' => 'desc', 'title' => 'asc'] (a list of keys, '-key' = descending, works too).
     *
     * @return array<int|string, string>
     */
    public function defaultSort(): array
    {
        return [];
    }

    public function perPage(): int
    {
        return 50;
    }

    /** Is the section shown to this person at all? */
    public function canSee(Viewer $viewer): bool
    {
        return true;
    }
}
