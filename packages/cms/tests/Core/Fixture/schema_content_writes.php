<?php

declare(strict_types=1);

use Itk\Cms\Field\Headline;
use Itk\Cms\Field\Select;
use Itk\Cms\Field\Text;
use Itk\Cms\Schema\SchemaBuilder;

$site = require __DIR__ . '/../../App/config/schema.php';

return static function (SchemaBuilder $s) use ($site): void {
    $site($s);
    $s->form('r2_content_form', 'Content regression')->fields(
        Select::make('State', 'state')->options(['yes' => 'Yes']),
        Headline::make('Headline', 'heading')->max(3),
        Text::make('Code', 'code')->pattern('/^valid$/', 'Use valid'),
    );
    $s->collection('r2_content_rows', 'Content regression')->fields(
        Text::make('Name', 'name'),
        Select::make('State', 'state')->options(['yes' => 'Yes']),
    );
};
