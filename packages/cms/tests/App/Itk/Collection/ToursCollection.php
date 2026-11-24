<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App\Itk\Collection;

use Itk\Cms\Attribute\Group;
use Itk\Cms\Attribute\Order;
use Itk\Cms\Field\Date;
use Itk\Cms\Field\Text;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\Metric;

/** Trips with dates: a collection with a calendar view, a default sort and metrics. */
#[Group('Коллекции', icon: 'layers', order: 50)]
#[Order(10)]
final class ToursCollection extends CollectionResource
{
    public function key(): string
    {
        return 'tours';
    }

    public function title(): string
    {
        return 'Поездки';
    }

    public function singular(): string
    {
        return 'поездка';
    }

    public function newLabel(): string
    {
        return 'Добавить поездку';
    }

    public function icon(): string
    {
        return 'map';
    }

    public function emptyState(): array
    {
        return ['title' => 'Поездок пока нет', 'text' => 'Добавьте первую поездку: город и даты.'];
    }

    public function views(): array
    {
        return ['table', 'calendar' => ['start' => 'dateStart', 'end' => 'dateEnd', 'title' => 'city']];
    }

    public function titleField(): string
    {
        return 'city';
    }

    public function subField(): string
    {
        return 'dateStart';
    }

    public function defaultSort(): array
    {
        return ['dateStart' => 'desc'];
    }

    public function fields(): iterable
    {
        yield Text::make('Город', 'city')->max(80)->required()->sortable()->searchable()->filterable();
        yield Date::make('Дата начала', 'dateStart')->required()->sortable()->filterable();
        yield Date::make('Дата окончания', 'dateEnd')->required()->sortable()->filterable();
    }

    public function metrics(): iterable
    {
        yield Metric::count('Всего');
        yield Metric::count('Ещё не начались', filter: ['dateStart' => ['from' => (new \DateTimeImmutable('today'))->format('Y-m-d')]]);
    }
}
