<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Attribute\Group;
use Itk\Cms\Attribute\Icon;
use Itk\Cms\Attribute\Order;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Field\Date;
use Itk\Cms\Field\FilterKind;
use Itk\Cms\Field\Number;
use Itk\Cms\Field\Select;
use Itk\Cms\Field\Text;
use Itk\Cms\Field\Textarea;
use Itk\Cms\Field\Toggle;
use Itk\Cms\Resource\Action;
use Itk\Cms\Resource\ActionContext;
use Itk\Cms\Resource\ActionResult;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\Context;
use Itk\Cms\Resource\Metric;
use Itk\Cms\Resource\Record;
use Itk\Cms\Security\Viewer;

/** Тестовая коллекция-класс: пишет в $log, что и в каком порядке вызвало ядро. */
#[Group('Тест', icon: 'box', order: 77)]
#[Order(5)]
#[Icon('map')]
final class TripsCollection extends CollectionResource
{
    /** @var list<string> */
    public static array $log = [];

    public function key(): string
    {
        return 'test_trips';
    }

    public function title(): string
    {
        return 'Тестовые поездки';
    }

    public function fields(): iterable
    {
        yield Text::make('Название', 'title')->required()->searchable()->sortable()->max(60);
        yield Date::make('Начало', 'start')->sortable()->filterable();
        yield Number::make('Мест', 'seats')->sortable()->filterable();
        yield Select::make('Набор', 'state')->options(['open' => 'Открыт', 'full' => 'Мест нет'])->sortable()->filterable();
        yield Select::make('Сложность', 'level')->options(['easy' => 'Лёгкая', 'mid' => 'Средняя', 'hard' => 'Сложная'])->filterable(FilterKind::Multi);
        yield Toggle::make('Горячая', 'hot')->filterable();
        yield Text::make('Секрет', 'secret')->canSee('set:users');
        yield Text::make('Служебное', 'sys')->readonly();
        yield Textarea::make('Заметка', 'note');
    }

    public function sortable(): bool
    {
        return false;
    }

    public function defaultSort(): array
    {
        return ['start' => 'asc'];
    }

    public function perPage(): int
    {
        return 3;
    }

    public function metrics(): iterable
    {
        yield Metric::count('Всего');
        yield Metric::count('Открыто', filter: ['state' => 'open']);
        yield Metric::count('Горячих', filter: ['hot' => true]);
        yield Metric::sum('Мест', 'seats');
    }

    public function actions(): iterable
    {
        yield Action::make('Пометить', 'tag')->row()->bulk()->icon('tag')
            ->modal('Пометка', Text::make('Пометка', 'label')->required(), Number::make('Сколько', 'times'))
            ->handle(static fn (ActionContext $c): ActionResult => ActionResult::message(\sprintf('%d: %s x%s', \count($c->records()), $c->input()['label'], $c->input()['times'] ?? '-'))->reload());
        yield Action::make('Только для админа', 'secretive')->top()->permission()->requires('view')
            ->handle(static fn (ActionContext $c): ActionResult => ActionResult::message('ok'));
        yield Action::make('Прячется', 'hidden')->top()->canSee(static fn (Viewer $v): bool => false)
            ->handle(static fn (ActionContext $c): ActionResult => ActionResult::message('no'));
        yield Action::make('Выгрузить', 'export')->top()
            ->handle(static function (ActionContext $c): ActionResult {
                $file = \tempnam(\sys_get_temp_dir(), 'test');
                \file_put_contents($file, "title\n" . \implode("\n", \array_map(static fn (Record $r): string => (string) $r->get('title'), $c->records())));

                return ActionResult::download($file, 'trips.csv', delete: true)->withMessage('Готово');
            });
        yield Action::make('Перейти', 'go')->top()->handle(static fn (ActionContext $c): ActionResult => ActionResult::redirect('/pages'));
        yield Action::make('Плохой переход', 'jump')->top()->handle(static fn (ActionContext $c): ActionResult => ActionResult::redirect('javascript:alert(1)'));
        yield Action::make('Ошибка', 'fails')->top()
            ->handle(static fn (ActionContext $c): ActionResult => ActionResult::error('Нечего делать'));
    }

    public function canSee(Viewer $viewer): bool
    {
        return true;
    }

    public function onLoad(Context $ctx): void
    {
        self::$log[] = 'method:onLoad';
    }

    public function beforeCreating(array $data, Context $ctx): array
    {
        self::$log[] = 'method:beforeCreating';
        $data['sys'] = 'made-by-hook';
        $data['note'] = ($data['note'] ?? '') . '+class';

        return $data;
    }

    public function afterCreated(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:afterCreated:' . $record->get('title');
    }

    public function beforeUpdating(Record $record, array $data, Context $ctx): array
    {
        self::$log[] = 'method:beforeUpdating';
        if ('forbidden' === ($data['title'] ?? null)) {
            throw ContentException::invalid('Это название нельзя');
        }

        return $data;
    }

    public function afterUpdated(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:afterUpdated:' . $record->get('title');
    }

    public function beforeDeleting(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:beforeDeleting';
        if ('protected' === $record->get('title')) {
            throw ContentException::conflict('Эту запись удалять нельзя');
        }
    }

    public function afterDeleted(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:afterDeleted:' . $record->get('title');
    }

    public function beforePublishing(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:beforePublishing';
        if ('no-publish' === $record->get('title')) {
            throw ContentException::conflict('Эту запись публиковать нельзя');
        }
    }

    public function afterPublished(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:afterPublished';
    }

    public function beforeUnpublishing(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:beforeUnpublishing';
    }

    public function afterUnpublished(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:afterUnpublished:' . $record->status;
    }

    public function beforeMassDeleting(array $records, Context $ctx): void
    {
        self::$log[] = 'method:beforeMassDeleting:' . \count($records);
        foreach ($records as $r) {
            if ('protected' === $r->get('title')) {
                throw ContentException::conflict('Среди выбранных есть защищённая запись');
            }
        }
    }

    public function afterMassDeleted(array $records, Context $ctx): void
    {
        self::$log[] = 'method:afterMassDeleted:' . \count($records);
    }

    public function afterMassPublished(array $records, Context $ctx): void
    {
        self::$log[] = 'method:afterMassPublished:' . \count($records);
    }
}
