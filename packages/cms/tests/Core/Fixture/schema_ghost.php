<?php

declare(strict_types=1);

use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\SchemaBuilder;

/* A good schema with a section whose Twig template does not exist (templates/sections/ghost.html.twig), and a template without fields. */
return static function (SchemaBuilder $s): void {
    $s->section('hero', 'Первый экран')->group('Первый экран')->fields(Field::headline('headline', 'Заголовок'), Field::description('description', 'Описание'));
    $s->section('faq', 'Вопросы')->fields(Field::repeater('items', 'Вопросы', Field::text('question', 'Вопрос'), Field::description('answer', 'Ответ')));
    $s->section('ghost', 'Секция без шаблона')->fields(Field::text('title', 'Заголовок'));
};
