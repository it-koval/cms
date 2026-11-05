<?php

declare(strict_types=1);

use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\SchemaBuilder;

/* The schema of the test site: only what the tests of the core need. */
return static function (SchemaBuilder $s): void {
    $s->design(headlineMarks: ['gradient']);

    $s->menuArea('header', 'Меню в шапке', depth: 2);
    $s->menuArea('footer', 'Подвал', depth: 1);

    $s->section('hero', 'Первый экран (hero)')->group('Первый экран')->short('Первый экран')->tone('dark')->silhouette('hero')->h1('headline')->fields(
        Field::select('layout', 'Вид', [['simple', 'Заголовок страницы'], ['home', 'Главная: заголовок, текст, кнопка, фото']])->variant(),
        Field::headline('headline', 'Заголовок страницы (H1)'),
        Field::headline('description', 'Описание'),
        Field::text('buttonText', 'Кнопка: текст'),
        Field::link('buttonLink', 'Кнопка: ссылка'),
        Field::image('image', 'Фото справа'),
    );

    $s->section('text', 'Текст')->group('Тексты и картинки')->tone('white')->silhouette('text')->fields(
        Field::article('text', 'Текст (абзацы, подзаголовки, списки, ссылки)'),
    );

    $s->section('html', 'HTML (перенесённая секция)')->group('Тексты и картинки')->short('HTML')->tone('gray')->silhouette('text')->fields(
        Field::textarea('html', 'HTML секции')->rows(15)->raw()->help('Выводится как есть. Менять код может только администратор.'),
    );

    $s->section('notes', 'Заметка с внутренним полем')->group('Тексты и картинки')->tone('white')->silhouette('text')->fields(
        Field::textarea('summary', 'Кратко'),
        Field::text('internal', 'Внутренняя пометка')->canSee('set:users'),
        Field::select('mood', 'Тон', [['calm', 'Спокойный'], ['loud', 'Громкий']]),
        Field::photos('gallery', 'Галерея'),
    );

    $s->section('image', 'Картинка')->group('Тексты и картинки')->tone('white')->silhouette('picture')->fields(
        Field::image('image', 'Картинка')->required(),
        Field::select('align', 'Выравнивание', [['center', 'По центру'], ['left', 'По левому краю']]),
    );

    $s->section('faq', 'Частые вопросы')->group('Карточки и списки')->tone('gray')->silhouette('faq')->fields(
        Field::headline('headline', 'Заголовок'),
        Field::repeater(
            'items',
            'Вопросы',
            Field::text('question', 'Вопрос')->required(),
            Field::description('answer', 'Ответ'),
        )->addLabel('Добавить вопрос')->noun('вопрос'),
    );

    $s->form('call', 'Заявка на звонок')->fields(
        Field::text('phone', 'Телефон')->max(32)->required()->pattern('/^\\+7?\\(([0-9]{3})\\)?[-]?([0-9]{3})[-]?([0-9]{2})[-]?([0-9]{2})$/', 'Проверьте номер телефона'),
        Field::text('city', 'Город')->max(120),
        Field::text('place', 'Откуда отправлено')->max(60),
    )->telegram("Заявка №{id}\nГород: {city}\nТелефон: {phone}\nФорма: {place}");

    $s->form('maintenance', 'Запись на ТО')->fields(
        Field::text('city', 'Город')->max(120),
        Field::text('name', 'Имя и фамилия')->max(120),
        Field::text('phone', 'Телефон')->max(32)->required(),
        Field::textarea('comment', 'На что обратить внимание')->max(1000),
    )->telegram("Заявка на ТО №{id}\nГород: {city}\nИмя: {name}\nТелефон: {phone}");

    $s->settings('site', 'Сайт')->fields(
        Field::text('siteName', 'Название сайта')->max(40)->required(),
        Field::text('domain', 'Адрес сайта')->max(60)->required(),
    );

    $s->settings('header', 'Шапка и кнопка заявки')->fields(
        Field::text('requestButtonText', 'Текст кнопки в шапке')->max(24)->translatable(),
        Field::select('requestButtonMode', 'Кнопка открывает', [['modal', 'Всплывающую форму'], ['anchor', 'Форму в подвале']])->initial('modal'),
    );

    $s->settings('contacts', 'Контакты и соцсети')->fields(
        Field::text('phone', 'Телефон')->max(24)->required(),
        Field::text('email', 'E-mail')->max(60)->inputType('email'),
    );
};
