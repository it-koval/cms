<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Media\ImageVariants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Пропорции и кадры: разбор «16:9», расчёт рамки по точке фокуса и по сохранённому кадру, адреса и srcset с пропорцией. */
final class ImageCropTest extends TestCase
{
    /** @return iterable<string, array{mixed, array{int, int}|null}> */
    public static function ratios(): iterable
    {
        yield 'plain' => ['16:9', [16, 9]];
        yield 'x' => ['4x3', [4, 3]];
        yield 'slash and spaces' => [' 3 / 4 ', [3, 4]];
        yield 'reduced' => ['32:18', [16, 9]];
        yield 'square' => ['10:10', [1, 1]];
        yield 'zero' => ['0:5', null];
        yield 'too big' => ['100:3', null];
        yield 'words' => ['wide', null];
        yield 'not a string' => [169, null];
        yield 'null' => [null, null];
    }

    /** @param array{int, int}|null $expected */
    #[Test]
    #[DataProvider('ratios')]
    public function parsesRatios(mixed $input, ?array $expected): void
    {
        self::assertSame($expected, ImageVariants::parseRatio($input));
    }

    #[Test]
    public function theFrameIsTheBiggestOneOfTheRatioAroundTheFocalPoint(): void
    {
        // 1500 × 900 to 1:1: 900 × 900
        self::assertSame([300, 0, 900, 900], ImageVariants::cropRect(1500, 900, [1, 1]), 'the centre');
        self::assertSame([0, 0, 900, 900], ImageVariants::cropRect(1500, 900, [1, 1], null, ['x' => 0, 'y' => 0]), 'kept inside the picture on the left');
        self::assertSame([600, 0, 900, 900], ImageVariants::cropRect(1500, 900, [1, 1], null, ['x' => 1, 'y' => 1]), 'and on the right');
        self::assertSame([450, 0, 900, 900], ImageVariants::cropRect(1500, 900, [1, 1], null, ['x' => 0.5 + 150 / 1500, 'y' => 0.5]), 'the frame is centred on the point');
        // 900 × 1500 to 16:9: the whole width, 506 high
        self::assertSame([0, 0, 900, 506], ImageVariants::cropRect(900, 1500, [16, 9], null, ['x' => 0.5, 'y' => 0]));
        self::assertSame([0, 994, 900, 506], ImageVariants::cropRect(900, 1500, [16, 9], null, ['x' => 0.5, 'y' => 1]));
        // a picture of the ratio itself is the whole picture
        self::assertSame([0, 0, 1600, 900], ImageVariants::cropRect(1600, 900, [16, 9], null, ['x' => 0.2, 'y' => 0.9]));
        // a broken focal point is the centre
        self::assertSame([300, 0, 900, 900], ImageVariants::cropRect(1500, 900, [1, 1], null, ['x' => 'left']));
    }

    #[Test]
    public function aSavedFrameWinsAndIsCutToTheRatioAroundItsCentre(): void
    {
        $crop = ['x' => 0.5, 'y' => 0, 'w' => 0.5, 'h' => 1];
        // 750 × 900 frame to 1:1: 750 × 750, centred vertically
        self::assertSame([750, 75, 750, 750], ImageVariants::cropRect(1500, 900, [1, 1], $crop, ['x' => 0, 'y' => 0]));
        // a frame that sticks out of the picture is moved back, then cut to 5:9 around its centre
        self::assertSame([875, 0, 500, 900], ImageVariants::cropRect(1500, 900, [5, 9], ['x' => 0.9, 'y' => 0, 'w' => 0.5, 'h' => 1]));
        // an empty frame is ignored
        self::assertSame([300, 0, 900, 900], ImageVariants::cropRect(1500, 900, [1, 1], ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0]));
    }

    #[Test]
    public function urlAndSrcsetCarryTheRatio(): void
    {
        $dir = \sys_get_temp_dir() . '/itk-crop-' . \bin2hex(\random_bytes(4));
        \mkdir($dir . '/2026/10', 0775, true);
        $img = \imagecreatetruecolor(1500, 900);
        \imagejpeg($img, $dir . '/2026/10/p-ab12.jpg');
        $v = new ImageVariants($dir, '/uploads/media', [320, 640, 960, 1280, 1920], 82, 50_000_000);
        try {
            self::assertSame('/uploads/media/_v/w640-r16x9/2026/10/p-ab12.webp', $v->url('/uploads/media/2026/10/p-ab12.jpg', 640, '16:9'));
            self::assertSame('/uploads/media/_v/w640-r16x9/2026/10/p-ab12.webp', $v->url('/uploads/media/2026/10/p-ab12.jpg', 640, '32x18'), 'one address per ratio');
            self::assertSame('/uploads/media/_v/w640/2026/10/p-ab12.webp', $v->url('/uploads/media/2026/10/p-ab12.jpg', 640, 'wide'), 'a broken ratio is the whole picture');
            self::assertSame('/uploads/media/_v/w640/2026/10/p-ab12.avif', $v->url('/uploads/media/2026/10/p-ab12.jpg', 640, null, 'avif'));
            self::assertSame('/uploads/media/_v/w640-r1x1/2026/10/p-ab12.avif', $v->url('/uploads/media/2026/10/p-ab12.jpg', 640, '1:1', 'avif'));
            self::assertSame(
                '/uploads/media/_v/w320-r4x3/2026/10/p-ab12.webp 320w, /uploads/media/_v/w640-r4x3/2026/10/p-ab12.webp 640w',
                $v->srcset('/uploads/media/2026/10/p-ab12.jpg', 600, null, '4:3'),
            );
            self::assertStringContainsString('/_v/w320/', $v->srcset('/uploads/media/2026/10/p-ab12.jpg', 600));
            self::assertSame('/uploads/media/2026/10/p.gif', $v->url('/uploads/media/2026/10/p.gif', 640, '16:9'), 'GIF passes through');
            self::assertTrue($v->isAllowedRatio('16:9'));
            self::assertFalse($v->isAllowedRatio('5:2'));
        } finally {
            \array_map('unlink', \glob($dir . '/2026/10/*') ?: []);
            \rmdir($dir . '/2026/10');
            \rmdir($dir . '/2026');
            \rmdir($dir);
        }
    }
}
