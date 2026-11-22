<?php

declare(strict_types=1);

use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\SchemaBuilder;

/* The test site plus forms with long fields. */
$site = require __DIR__ . '/../../App/config/schema.php';

return static function (SchemaBuilder $s) use ($site): void {
    $site($s);

    $s->form('long_order', 'Заказ из корзины')->fields(
        Field::text('name', 'Имя')->max(120)->required(),
        Field::text('phone', 'Телефон')->max(32)->required()->pattern('/^\+7[0-9]{10}$/', 'Проверьте номер телефона'),
        Field::textarea('items', 'Состав заказа')->max(6000)->required(),
        Field::textarea('remark', 'Пометка')->max(120),
        Field::textarea('free', 'Поле без предела'),
        Field::textarea('code', 'Код')->max(6000)->pattern('/^[^#]*$/', 'Без решётки'),
        Field::toggle('consent', 'Согласие')->required(),
    )->telegram("Заказ №{id}\n\nИмя: {name}\nТелефон: {phone}\n\nСостав:\n{items}\n\nПометка: {remark}");

    $s->form('long_card', 'Длинная карточка')->fields(
        Field::text('name', 'Имя')->max(500),
        Field::text('phone', 'Телефон')->max(64),
        Field::text('city', 'Город')->max(500),
        Field::textarea('message', 'Сообщение')->max(6000),
        Field::textarea('items', 'Состав')->max(6000),
    );

    $s->form('long_text', 'Длинный текст')->fields(Field::textarea('items', 'Состав')->max(20000))->telegram('{items}');
    $s->form('long_bold', 'Текст и выделение')->fields(Field::textarea('head', 'Начало'), Field::textarea('items', 'Состав'))->telegram("{head}\n<b>{items}</b>");
    $s->form('long_marked', 'Длинное выделение')->fields(Field::textarea('items', 'Состав'))->telegram("<b>Заказ</b>\n<a href=\"{url}\"><i>{items}</i></a>");
    $s->form('long_plain', 'Без шаблона')->fields(Field::textarea('items', 'Состав')->max(20000));
};
