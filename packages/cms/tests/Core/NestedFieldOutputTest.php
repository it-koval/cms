<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Field\Image;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Text;
use Itk\Cms\Security\Viewer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NestedFieldOutputTest extends TestCase
{
    #[Test]
    public function nestedVisibilityRemovesOnlyUnreadableFieldsAndPreservesRows(): void
    {
        $fields = [Repeater::make('Rows', 'rows')->fields(
            Text::make('Public', 'public'),
            Image::make('Private picture', 'private')->canSee('set:users'),
            Repeater::make('Children', 'children')->fields(
                Text::make('Public', 'public'),
                Image::make('Private picture', 'private')->canSee('set:users'),
            ),
        )];
        $values = ['rows' => [['_id' => 'outer', 'public' => 'Visible', 'private' => ['src' => '/uploads/private.jpg'],
            'children' => [['_id' => 'inner', 'public' => 'Also visible', 'private' => ['src' => '/uploads/private.jpg']]],
        ]]];
        self::assertSame(['rows' => [['_id' => 'outer', 'public' => 'Visible',
            'children' => [['_id' => 'inner', 'public' => 'Also visible']],
        ]]], FieldAccess::outputFor($fields, $values, Viewer::anonymous()));
        self::assertSame($values, FieldAccess::outputFor($fields, $values, Viewer::system()));
    }
}
