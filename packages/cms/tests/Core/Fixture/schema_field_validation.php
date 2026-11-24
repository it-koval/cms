<?php

declare(strict_types=1);
use Itk\Cms\Field\Date;
use Itk\Cms\Field\Select;
use Itk\Cms\Field\Text;
use Itk\Cms\Schema\SchemaBuilder;

$base = require __DIR__ . '/../../App/config/schema.php';

return static function (SchemaBuilder $s) use ($base): void {
    $base($s);
    $s->form('faqItems', 'Validation form')->fields(Text::make('Name', 'name')->max(5), Date::make('Date', 'date'), Select::make('State', 'state')->options(['open' => 'Open']));
    $s->collection('faqItems', 'Validation items')->fields(Text::make('Title', 'title')->max(5));
};
