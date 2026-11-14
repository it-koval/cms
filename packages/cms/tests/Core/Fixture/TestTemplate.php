<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Field\Text;
use Itk\Cms\Resource\Context;
use Itk\Cms\Resource\Record;
use Itk\Cms\Resource\TemplateResource;
use Itk\Cms\Schema\Template;

/** Тестовый шаблон страницы-класс. */
final class TestTemplate extends TemplateResource
{
    /** @var list<string> */
    public static array $log = [];

    public function key(): string
    {
        return 'test_tpl';
    }

    public function title(): string
    {
        return 'Тестовый шаблон';
    }

    public function fields(): iterable
    {
        yield Text::make('Подзаголовок', 'sub')->max(40);
        yield Text::make('Секрет', 'secret')->canSee('set:users');
    }

    public function about(): string
    {
        return 'Для тестов.';
    }

    public function parents(): array
    {
        return ['default'];
    }

    public function shortcut(): ?string
    {
        return 'Тестовые страницы';
    }

    public function configure(Template $template): void
    {
        $template->leaf();
    }

    public function beforeCreating(array $data, Context $ctx): array
    {
        self::$log[] = 'method:beforeCreating';
        $data['fields']['sub'] = ($data['fields']['sub'] ?? '') . '!';

        return $data;
    }

    public function afterCreated(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:afterCreated:' . $record->resourceKey . ':' . $record->get('title');
    }

    public function beforeUpdating(Record $record, array $data, Context $ctx): array
    {
        self::$log[] = 'method:beforeUpdating';

        return $data;
    }

    public function afterUpdated(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:afterUpdated';
    }

    public function beforePublishing(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:beforePublishing';
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
        self::$log[] = 'method:afterUnpublished';
    }

    public function beforeDeleting(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:beforeDeleting';
    }

    public function afterDeleted(Record $record, Context $ctx): void
    {
        self::$log[] = 'method:afterDeleted';
    }

    public function beforeMassDeleting(array $records, Context $ctx): void
    {
        self::$log[] = 'method:beforeMassDeleting:' . \count($records);
    }

    public function afterMassPublished(array $records, Context $ctx): void
    {
        self::$log[] = 'method:afterMassPublished:' . \count($records);
    }
}
