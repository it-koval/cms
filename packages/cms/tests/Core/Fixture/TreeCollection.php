<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Attribute\Group;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Field\Date;
use Itk\Cms\Field\Select;
use Itk\Cms\Field\Text;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\Context;
use Itk\Cms\Resource\Record;

/** Тестовая коллекция с вложенностью и видами «календарь» и «доска»; пишет в $log, какие хуки вызвало перемещение. */
#[Group('Тест', icon: 'box', order: 78)]
final class TreeCollection extends CollectionResource
{
    /** @var list<string> */
    public static array $log = [];

    public function key(): string
    {
        return 'test_tree';
    }

    public function title(): string
    {
        return 'Тестовое дерево';
    }

    public function fields(): iterable
    {
        yield Text::make('Название', 'title')->required()->searchable()->sortable()->max(60);
        yield Date::make('Начало', 'start')->sortable()->filterable();
        yield Date::make('Конец', 'end');
        yield Select::make('Этап', 'stage')->options(['todo' => 'Сделать', 'doing' => 'В работе', 'done' => 'Готово'])->filterable();
    }

    public function tree(): bool
    {
        return true;
    }

    public function views(): array
    {
        return [
            'table',
            'calendar' => ['start' => 'start', 'end' => 'end', 'title' => 'title'],
            'kanban' => ['field' => 'stage'],
        ];
    }

    public function beforeUpdating(Record $record, array $data, Context $ctx): array
    {
        self::$log[] = 'method:beforeUpdating:' . \implode(',', \array_keys($data));
        if ('no-move' === $record->get('title') && \array_key_exists('parentId', $data)) {
            throw ContentException::conflict('Эту запись нельзя переносить');
        }

        return $data;
    }

    public function afterUpdated(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:afterUpdated:' . $record->get('title');
    }
}
