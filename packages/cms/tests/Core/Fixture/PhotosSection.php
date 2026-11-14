<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Itk\Cms\Field\Image;
use Itk\Cms\Field\Photos;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Text;
use Itk\Cms\Resource\SectionResource;

/** Секция с картинкой, галереей и списком, в элементах которого тоже есть галерея. */
final class PhotosSection extends SectionResource
{
    public function key(): string
    {
        return 'test_photos';
    }

    public function title(): string
    {
        return 'Test фото';
    }

    public function fields(): iterable
    {
        yield Image::make('Обложка', 'cover');
        yield Photos::make('Галерея', 'gallery');
        yield Repeater::make('Номера', 'rooms')->fields(
            Text::make('Название', 'title'),
            Photos::make('Фото', 'photos'),
        );
    }
}
