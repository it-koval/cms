<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\FieldValues;
use Itk\Cms\Field\Number;
use Itk\Cms\Field\Photos;
use Itk\Cms\Field\Repeater;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Поле «Photos»: элемент — адрес (админка) или {src, alt} (MCP), форма элемента сохраняется; опасные схемы адресов отбрасываются. */
final class PhotosValueTest extends KernelTestCase
{
    private function normalize(mixed $in): mixed
    {
        return self::getContainer()->get(FieldValues::class)->normalize([Photos::make('Фото', 'photos')], ['photos' => $in])['photos'];
    }

    #[Test]
    public function aStringStaysAStringAndAnObjectStaysAnObjectWithItsAlt(): void
    {
        $out = $this->normalize(['  /uploads/a.jpg ', ['src' => ' /uploads/b.jpg', 'alt' => ' Б '], ['src' => '/uploads/c.jpg'], ['src' => '/uploads/d.jpg', 'alt' => 'Д', 'caption' => 'Подпись', 'w' => 800]]);

        // no alt is added to an object without one, and its other keys stay
        self::assertSame(['/uploads/a.jpg', ['src' => '/uploads/b.jpg', 'alt' => 'Б'], ['src' => '/uploads/c.jpg'], ['src' => '/uploads/d.jpg', 'alt' => 'Д', 'caption' => 'Подпись', 'w' => 800]], $out);
    }

    #[Test]
    public function addressesWithoutASchemeStay(): void
    {
        $in = ['/uploads/a.jpg', 'uploads/b.jpg', 'assets/c.jpg', '//cdn.example.com/d.jpg', 'https://example.com/e.jpg', 'http://example.com/f.jpg', 'data:image/png;base64,AAAA', ['src' => 'uploads/g.jpg', 'alt' => 'г'], ['src' => '//cdn.example.com/h.jpg', 'alt' => '']];

        self::assertSame($in, $this->normalize($in));
    }

    #[Test]
    public function itemsWithoutAnAddressOrWithADangerousSchemeAreDropped(): void
    {
        $in = [
            '/uploads/a.jpg', 'javascript:alert(1)', 'JavaScript:alert(1)', " \tjavascript:alert(1)", "java\nscript:alert(1)", 'vbscript:msgbox(1)', 'data:text/html;base64,PHNjcmlwdD4=', 'file:///etc/passwd',
            ['src' => 'javascript:alert(1)', 'alt' => 'x'], ['src' => 'data:text/html,<b>', 'alt' => 'x'], ['src' => '', 'alt' => 'пусто'], ['alt' => 'без адреса'], ['src' => ['/a.jpg']],
            '', '   ', null, 5, true, [],
        ];

        self::assertSame(['/uploads/a.jpg'], $this->normalize($in));
        self::assertSame([], $this->normalize('not a list'));
        self::assertSame([], $this->normalize(null));
    }

    #[Test]
    public function normalizingTwiceChangesNothingAndValidDataComesBackAsItWas(): void
    {
        $stored = ['/uploads/a.jpg', ['src' => '/uploads/b.jpg', 'alt' => 'Б'], 'uploads/c.jpg', ['src' => '//cdn.example.com/d.jpg', 'alt' => '']];
        $once = $this->normalize($stored);

        self::assertSame($stored, $once);
        self::assertSame($once, $this->normalize($once));
        self::assertSame($once, $this->normalize(['  /uploads/a.jpg ', ['src' => '/uploads/b.jpg ', 'alt' => ' Б'], 'uploads/c.jpg', ['src' => '//cdn.example.com/d.jpg', 'alt' => ''], 'javascript:x']));
    }

    #[Test]
    public function savingARecordWithTheOldValueDoesNotMarkTheFieldAsChanged(): void
    {
        $values = self::getContainer()->get(FieldValues::class);
        $fields = [Photos::make('Фото', 'photos')];
        $published = ['photos' => ['/uploads/a.jpg', ['src' => '/uploads/b.jpg', 'alt' => 'Б'], 'uploads/c.jpg']];

        $saved = $values->normalize($fields, $published);

        self::assertSame([], $values->changedKeys($fields, $saved, $published));
        self::assertSame(['photos'], $values->changedKeys($fields, $values->normalize($fields, ['photos' => [...$published['photos'], '/uploads/d.jpg']]), $published));
    }

    #[Test]
    public function photosInsideARepeaterRowAreNormalizedToo(): void
    {
        $values = self::getContainer()->get(FieldValues::class);
        $f = Repeater::make('Варианты', 'variants')->fields(Number::make('Цена', 'price'), Photos::make('Фото', 'photos'));
        $row = ['_id' => 'v1', 'price' => '10', 'photos' => [['src' => '/uploads/c.jpg', 'alt' => 'ц'], ' /uploads/d.jpg', 'uploads/e.jpg', 'javascript:alert(1)', ['src' => 'vbscript:x']]];

        $out = $values->normalize([$f], ['variants' => [$row]]);

        self::assertSame([['src' => '/uploads/c.jpg', 'alt' => 'ц'], '/uploads/d.jpg', 'uploads/e.jpg'], $out['variants'][0]['photos']);
        self::assertEquals($out, $values->normalize([$f], $out), 'the second pass changes nothing');
    }
}
