<?php

declare(strict_types=1);

use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\SchemaBuilder;

/* A schema with mistakes: a field key twice, a form without fields, a section whose Twig template does not exist. */
return static function (SchemaBuilder $s): void {
    $s->section('ghost', 'Секция без шаблона')->fields(Field::text('title', 'Заголовок'), Field::text('title', 'Ещё заголовок'));
    $s->form('empty', 'Пустая форма');
};
