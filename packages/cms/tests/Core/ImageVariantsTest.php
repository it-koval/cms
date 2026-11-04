<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Media\ImageVariants;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Уменьшенные WebP-копии картинок медиатеки: адреса, srcset, создание файла, текст статьи. */
final class ImageVariantsTest extends TestCase
{
    private const WIDTHS = [320, 640, 960, 1280, 1920, 2560];

    private string $dir;
    private ImageVariants $variants;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/itk-variants-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/2026/10', 0775, true);
        $this->variants = $this->make();
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? \rmdir($f->getPathname()) : \unlink($f->getPathname());
        }
        \rmdir($this->dir);
    }

    private function make(int $maxPixels = 50_000_000): ImageVariants
    {
        return new ImageVariants($this->dir, '/uploads/media', self::WIDTHS, 82, $maxPixels);
    }

    /** A noisy picture, so that JPEG is not tiny. */
    private function picture(string $name, int $w, int $h, string $format = 'jpg'): string
    {
        $img = \imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 400; ++$i) {
            \imagefilledellipse($img, \random_int(0, $w), \random_int(0, $h), \random_int(10, 200), \random_int(10, 200), (int) \imagecolorallocate($img, \random_int(0, 255), \random_int(0, 255), \random_int(0, 255)));
        }
        $path = $this->dir . '/2026/10/' . $name;
        'png' === $format ? \imagepng($img, $path) : \imagejpeg($img, $path, 95);

        return $path;
    }

    #[Test]
    public function slotRatioIsTheReducedFractionOrTheNearestOneThatFits(): void
    {
        self::assertSame('14:5', ImageVariants::slotRatio(700, 250));
        self::assertSame('69:50', ImageVariants::slotRatio(345, 250));
        self::assertSame('1:1', ImageVariants::slotRatio(245, 245));
        self::assertSame('5:3', ImageVariants::slotRatio(650, 390));
        // 1095x515 reduces to 219:103 (sides above 99): the nearest ratio, off by well under 1%
        $near = ImageVariants::slotRatio(1095, 515);
        self::assertSame('17:8', $near);
        $pair = ImageVariants::parseRatio($near);
        self::assertLessThan(0.01, \abs($pair[0] / $pair[1] - 1095 / 515) / (1095 / 515));
        // 345x520 reduces to 69:104
        $pair = ImageVariants::parseRatio(ImageVariants::slotRatio(345, 520));
        self::assertLessThan(0.01, \abs($pair[0] / $pair[1] - 345 / 520) / (345 / 520));
        self::assertNull(ImageVariants::slotRatio(0, 250));
        self::assertNull(ImageVariants::slotRatio(700, -1));
    }

    #[Test]
    public function slotRatioVariantHasTheShapeOfItsSlot(): void
    {
        $this->picture('tile-cd34.jpg', 1400, 1900);
        $ratio = ImageVariants::slotRatio(700, 250);
        $url = $this->variants->url('/uploads/media/2026/10/tile-cd34.jpg', 700, $ratio);
        self::assertSame('/uploads/media/_v/w960-r14x5/2026/10/tile-cd34.webp', $url);
        $file = $this->variants->generate('/uploads/media/2026/10/tile-cd34.jpg', 960, 'webp', ImageVariants::parseRatio($ratio));
        self::assertNotNull($file);
        [$w, $h] = \getimagesize($file);
        self::assertSame(960, $w);
        self::assertSame(343, $h);
    }

    #[Test]
    public function buildsVariantUrlsAndSnapsToAllowedWidths(): void
    {
        $this->picture('photo-ab12.jpg', 3000, 2000);
        self::assertSame('/uploads/media/_v/w640/2026/10/photo-ab12.webp', $this->variants->url('/uploads/media/2026/10/photo-ab12.jpg', 640));
        self::assertSame('/uploads/media/_v/w1280/2026/10/photo-ab12.webp', $this->variants->url('/uploads/media/2026/10/photo-ab12.jpg', 1000));
        self::assertSame('/uploads/media/_v/w2560/2026/10/photo-ab12.webp', $this->variants->url('/uploads/media/2026/10/photo-ab12.jpg', 9999));
    }

    #[Test]
    public function passesThroughWhatIsNotConverted(): void
    {
        foreach (['/uploads/media/2026/10/a.gif', '/uploads/media/2026/10/a.svg', '/uploads/media/2026/10/a.pdf', 'https://example.com/a.jpg', '/assets/img/a.jpg', '/uploads/media/_v/w640/2026/10/a.webp', '/uploads/media/../a.jpg'] as $url) {
            self::assertSame($url, $this->variants->url($url, 640), $url);
            self::assertSame('', $this->variants->srcset($url), $url);
            self::assertFalse($this->variants->isConvertible($url), $url);
        }
    }

    #[Test]
    public function srcsetListsWidthsBelowTheOriginalAndOneThatCoversIt(): void
    {
        $this->picture('big-ab12.jpg', 3000, 1000);
        $this->picture('mid-ab12.jpg', 1000, 600);
        $big = $this->variants->srcset('/uploads/media/2026/10/big-ab12.jpg');
        self::assertSame(
            '/uploads/media/_v/w320/2026/10/big-ab12.webp 320w, /uploads/media/_v/w640/2026/10/big-ab12.webp 640w, /uploads/media/_v/w960/2026/10/big-ab12.webp 960w, '
            . '/uploads/media/_v/w1280/2026/10/big-ab12.webp 1280w, /uploads/media/_v/w1920/2026/10/big-ab12.webp 1920w, /uploads/media/_v/w2560/2026/10/big-ab12.webp 2560w',
            $big,
        );
        // 1000 px: 320, 640, 960 and the 1280 variant, which is the 1000 px original, labelled 1000w
        self::assertSame(
            '/uploads/media/_v/w320/2026/10/mid-ab12.webp 320w, /uploads/media/_v/w640/2026/10/mid-ab12.webp 640w, /uploads/media/_v/w960/2026/10/mid-ab12.webp 960w, /uploads/media/_v/w1280/2026/10/mid-ab12.webp 1000w',
            $this->variants->srcset('/uploads/media/2026/10/mid-ab12.jpg'),
        );
        // a layout 650 px wide shows at most 1300 device pixels
        self::assertStringEndsWith('/w1280/2026/10/big-ab12.webp 1280w', $this->variants->srcset('/uploads/media/2026/10/big-ab12.jpg', 1300));
        self::assertSame(1280, \array_column($this->variants->candidates(3000, 1300), 0)[4 - 1]);
        // the width of the media library wins when the file is not at hand
        self::assertSame('/uploads/media/_v/w320/2026/10/gone.webp 320w, /uploads/media/_v/w640/2026/10/gone.webp 500w', $this->variants->srcset('/uploads/media/2026/10/gone.jpg', null, 500));
    }

    #[Test]
    public function aVariantIsASmallerWebpAndNeverEnlarged(): void
    {
        $orig = $this->picture('photo-ab12.jpg', 1600, 1000);
        $path = $this->variants->ensure($orig, 640, '2026/10/photo-ab12.webp');
        self::assertSame($this->dir . '/_v/w640/2026/10/photo-ab12.webp', $path);
        self::assertSame(\IMAGETYPE_WEBP, \getimagesize($path)[2]);
        self::assertSame([640, 400], [\getimagesize($path)[0], \getimagesize($path)[1]]);
        self::assertLessThan(\filesize($orig), \filesize($path));

        // wider than the original: a WebP of the original size
        $big = $this->variants->ensure($orig, 2560, '2026/10/photo-ab12.webp');
        self::assertSame([1600, 1000], [\getimagesize($big)[0], \getimagesize($big)[1]]);
        // and the address does not ask for a width the picture cannot fill
        self::assertSame('/uploads/media/_v/w1920/2026/10/photo-ab12.webp', $this->variants->url('/uploads/media/2026/10/photo-ab12.jpg', 2560));
        self::assertSame([], \glob($this->dir . '/_v/w640/2026/10/.tmp-*') ?: []);
    }

    #[Test]
    public function aPngKeepsItsTransparency(): void
    {
        $img = \imagecreatetruecolor(800, 400);
        \imagealphablending($img, false);
        \imagesavealpha($img, true);
        \imagefill($img, 0, 0, (int) \imagecolorallocatealpha($img, 0, 0, 0, 127));
        \imagefilledellipse($img, 400, 200, 300, 300, (int) \imagecolorallocatealpha($img, 255, 0, 0, 0));
        $orig = $this->dir . '/2026/10/logo-ab12.png';
        \imagepng($img, $orig);

        $path = (string) $this->variants->ensure($orig, 320, '2026/10/logo-ab12.webp');
        $webp = \imagecreatefromwebp($path);
        self::assertSame([320, 160], [\imagesx($webp), \imagesy($webp)]);
        self::assertSame(127, (\imagecolorat($webp, 2, 2) >> 24) & 0x7F, 'the corner stays transparent');
        self::assertSame(0, (\imagecolorat($webp, 160, 80) >> 24) & 0x7F, 'the middle stays opaque');
    }

    #[Test]
    public function refusesPicturesAboveThePixelLimit(): void
    {
        $orig = $this->picture('photo-ab12.jpg', 1600, 1000);
        $strict = $this->make(1_000_000);
        self::assertNull($strict->ensure($orig, 640, '2026/10/photo-ab12.webp'));
        self::assertFileDoesNotExist($this->dir . '/_v/w640/2026/10/photo-ab12.webp');
        self::assertSame('/uploads/media/2026/10/photo-ab12.jpg', $strict->url('/uploads/media/2026/10/photo-ab12.jpg', 640), 'the original is used');
        self::assertSame('', $strict->srcset('/uploads/media/2026/10/photo-ab12.jpg'));
    }

    #[Test]
    public function findsTheOriginalOnlyInsideTheUploadDir(): void
    {
        $orig = $this->picture('photo-ab12.jpg', 400, 300);
        \file_put_contents(\dirname($this->dir) . '/itk-outside.jpg', 'x');
        try {
            self::assertSame(\realpath($orig), $this->variants->resolveOriginal('2026/10/photo-ab12.webp')[0]);
            self::assertSame('2026/10/photo-ab12.jpg', $this->variants->resolveOriginal('2026/10/photo-ab12.webp')[1]);
            foreach (['../itk-outside.webp', '2026/../../itk-outside.webp', '/2026/10/photo-ab12.webp', '2026/10/photo-ab12.jpg', '2026/10/missing.webp', "2026/10/photo-ab12\0.webp", '_v/w640/2026/10/photo-ab12.webp'] as $bad) {
                self::assertNull($this->variants->resolveOriginal($bad), $bad);
            }
        } finally {
            \unlink(\dirname($this->dir) . '/itk-outside.jpg');
        }
    }

    #[Test]
    public function removeDeletesEveryVariantOfTheFile(): void
    {
        $orig = $this->picture('photo-ab12.jpg', 1600, 1000);
        $other = $this->picture('other-cd34.jpg', 1600, 1000);
        foreach ([320, 640] as $w) {
            $this->variants->ensure($orig, $w, '2026/10/photo-ab12.webp');
            $this->variants->ensure($other, $w, '2026/10/other-cd34.webp');
        }
        $this->variants->remove('/uploads/media/2026/10/photo-ab12.jpg');
        self::assertFileDoesNotExist($this->dir . '/_v/w320/2026/10/photo-ab12.webp');
        self::assertFileDoesNotExist($this->dir . '/_v/w640/2026/10/photo-ab12.webp');
        self::assertFileExists($this->dir . '/_v/w640/2026/10/other-cd34.webp');
    }

    #[Test]
    public function richTextImagesGetVariantsAtRenderTime(): void
    {
        $this->picture('photo-ab12.jpg', 3000, 2000);
        $html = '<p>Text</p><img src="/uploads/media/2026/10/photo-ab12.jpg" alt="A &amp; B"><img src="https://example.com/x.jpg" alt=""><img src="/uploads/media/2026/10/anim.gif" alt="">';
        $out = $this->variants->rewriteHtml($html);
        self::assertStringContainsString('<img src="/uploads/media/_v/w960/2026/10/photo-ab12.webp" srcset="/uploads/media/_v/w320/2026/10/photo-ab12.webp 320w,', $out);
        self::assertStringContainsString('1920w" sizes="(max-width: 800px) 100vw, 800px" alt="A &amp; B" loading="lazy" decoding="async">', $out);
        self::assertStringNotContainsString('w2560', $out);
        self::assertStringContainsString('<img src="https://example.com/x.jpg" alt="">', $out);
        self::assertStringContainsString('<img src="/uploads/media/2026/10/anim.gif" alt="">', $out);
        self::assertSame('<p>No images</p>', $this->variants->rewriteHtml('<p>No images</p>'));
    }

    #[Test]
    public function richTextImageWithSizeKeepsItAndSizesFollowIt(): void
    {
        $this->picture('photo-ab12.jpg', 3000, 2000);
        $out = $this->variants->rewriteHtml('<p>x</p><img src="/uploads/media/2026/10/photo-ab12.jpg" alt="A" width="399" height="384">');
        // the smallest variant that covers 399 px; srcset up to twice that for retina; sizes = the width the author set
        self::assertStringContainsString('<img src="/uploads/media/_v/w640/2026/10/photo-ab12.webp" srcset="/uploads/media/_v/w320/2026/10/photo-ab12.webp 320w,', $out);
        self::assertStringContainsString('sizes="(max-width: 399px) 100vw, 399px" alt="A" width="399" height="384" loading="lazy" decoding="async">', $out);
        self::assertStringNotContainsString('w1280', $out); // 2 x 399 = 798: the 960 variant covers it
        self::assertStringContainsString('960w', $out);
    }

    #[Test]
    public function richTextImageKeepsItsAlignment(): void
    {
        $this->picture('photo-ab12.jpg', 3000, 2000);
        $out = $this->variants->rewriteHtml('<img src="/uploads/media/2026/10/photo-ab12.jpg" alt="" width="400" height="300" data-align="center">');
        self::assertStringContainsString('width="400" height="300" data-align="center" loading="lazy" decoding="async">', $out);
    }

    #[Test]
    public function richTextImageWithOnlyAWidthKeepsOnlyTheWidth(): void
    {
        $this->picture('photo-ab12.jpg', 3000, 2000);
        $out = $this->variants->rewriteHtml('<img src="/uploads/media/2026/10/photo-ab12.jpg" alt="" width="650">');
        self::assertStringContainsString('sizes="(max-width: 650px) 100vw, 650px" alt="" width="650" loading="lazy"', $out);
        self::assertStringNotContainsString('height=', $out);
    }

    #[Test]
    public function richTextImageIsNeverShownWiderThanItsFile(): void
    {
        $this->picture('small-cd34.jpg', 300, 200);
        $out = $this->variants->rewriteHtml('<img src="/uploads/media/2026/10/small-cd34.jpg" alt="" width="650" height="433">');
        // 650 > 300: width and height shrink in proportion (433 * 300 / 650 = 200)
        self::assertStringContainsString('sizes="(max-width: 300px) 100vw, 300px" alt="" width="300" height="200"', $out);
        self::assertStringContainsString('<img src="/uploads/media/_v/w320/2026/10/small-cd34.webp"', $out);
    }

    #[Test]
    public function originalWidthIsTheFilesOwnWidth(): void
    {
        $this->picture('photo-ab12.jpg', 1234, 800);
        self::assertSame(1234, $this->variants->originalWidth('/uploads/media/2026/10/photo-ab12.jpg'));
        self::assertNull($this->variants->originalWidth('https://example.com/x.jpg'));
        self::assertNull($this->variants->originalWidth('/uploads/media/2026/10/missing.jpg'));
    }

    #[Test]
    public function turnsAJpegByItsExifOrientation(): void
    {
        // 300x200: red left half, blue right half; orientation 6 = shown turned 90° clockwise, so red ends up on top
        $img = \imagecreatetruecolor(300, 200);
        \imagefilledrectangle($img, 0, 0, 149, 199, (int) \imagecolorallocate($img, 255, 0, 0));
        \imagefilledrectangle($img, 150, 0, 299, 199, (int) \imagecolorallocate($img, 0, 0, 255));
        \ob_start();
        \imagejpeg($img, null, 95);
        $jpeg = (string) \ob_get_clean();
        $exif = "Exif\0\0" . 'MM' . "\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00\x00\x00\x00\x00";
        $orig = $this->dir . '/2026/10/turned-ab12.jpg';
        \file_put_contents($orig, \substr($jpeg, 0, 2) . "\xFF\xE1" . \pack('n', \strlen($exif) + 2) . $exif . \substr($jpeg, 2));

        self::assertSame([200, 300], $this->variants->dimensions($orig));
        $path = (string) $this->variants->ensure($orig, 100, '2026/10/turned-ab12.webp');
        $webp = \imagecreatefromwebp($path);
        self::assertSame([100, 150], [\imagesx($webp), \imagesy($webp)]);
        $top = \imagecolorat($webp, 50, 10);
        $bottom = \imagecolorat($webp, 50, 140);
        self::assertGreaterThan(200, ($top >> 16) & 0xFF, 'red on top');
        self::assertGreaterThan(200, $bottom & 0xFF, 'blue below');
    }

    #[Test]
    public function withoutGdNoCopyIsMadeAndTheAddressesPointToTheOriginal(): void
    {
        $path = $this->picture('nogd.jpg', 1500, 900);
        $noGd = new ImageVariants($this->dir, '/uploads/media', self::WIDTHS, 82, 50_000_000, gdWebp: false);

        self::assertNull($noGd->ensure($path, 640, '2026/10/nogd.webp'), 'no WebP writer: the caller serves the original');
        self::assertNull($noGd->generate('/uploads/media/2026/10/nogd.jpg', 640));
        self::assertFileDoesNotExist($this->dir . '/_v/w640/2026/10/nogd.webp');
        self::assertSame('/uploads/media/2026/10/nogd.jpg', $noGd->url('/uploads/media/2026/10/nogd.jpg', 640), 'a page never points at a copy that cannot be made');
        self::assertSame('', $noGd->srcset('/uploads/media/2026/10/nogd.jpg', null, 1500));
        self::assertSame(['made' => 0, 'existing' => 0, 'skipped' => 0], $noGd->warm('/uploads/media/2026/10/nogd.jpg', 1500));
    }
}
