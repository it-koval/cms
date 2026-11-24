<?php

declare(strict_types=1);

use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\SchemaBuilder;

return static function (SchemaBuilder $schema): void {
    $fields = static fn (): array => [
        Field::text('top_secret', 'Top-level secret')->canSee('set:users'),
        Field::text('top_locked', 'Top-level readonly')->readonly(),
        Field::repeater(
            'rows',
            'Rows',
            Field::text('plain', 'Plain'),
            Field::text('unlocked', 'Unlocked')->readonly(false),
            Field::note('note', 'Note'),
            Field::text('secret', 'Secret')->canSee('set:users'),
            Field::text('locked', 'Readonly')->readonly(),
            Field::text('both', 'Both')->canSee(static fn (): bool => true)->readonly(),
            Field::repeater(
                'children',
                'Children',
                Field::text('deep', 'Deep')->canSee(static function (): never {
                    throw new \LogicException('Lint must not execute canSee closures.');
                })->readonly(),
            )->canSee('set:users')->readonly(),
        )->canSee('set:users')->readonly(),
    ];

    $schema->section('nested_section')->fields(...$fields());
    $schema->template('nested_template')->fields(...$fields());
    $schema->collection('nested_collection')
        ->tab('main', 'Main', Field::text('title', 'Title'))
        ->tab('extra', 'Extra', ...$fields());
    $schema->settings('nested_settings')->fields(...$fields());
};
