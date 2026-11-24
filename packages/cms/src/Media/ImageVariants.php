<?php

declare(strict_types=1);

namespace Itk\Cms\Media;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resized WebP and AVIF copies of the uploaded images.
 *
 *   original   {upload_url}/Y/m/name.jpg
 *   variant    {upload_url}/_v/w{width}/Y/m/name.webp            (and name.avif)
 *   ratio      {upload_url}/_v/w{width}-r{A}x{B}/Y/m/name.webp   (a frame of the ratio A:B, see cropRect())
 *
 * A variant is made right after the upload (Messenger message GenerateImageVariants) or, when it is missing, by
 * Controller\Site\ImageVariantController on the first request, and written next to the public path, so every next request
 * is served by the web server. Only widths from itk_cms.images.widths exist; the image is never enlarged (a width above
 * the original gives a copy of the original size). A ratio is allowed when it is in itk_cms.images.ratios or the file has
 * a frame of it (MediaFile::$crops). JPG, PNG, WebP and AVIF are converted; GIF, SVG, PDF, other addresses and originals
 * above itk_cms.images.max_pixels are passed through unchanged.
 */
final class ImageVariants
{
    public const DIR = '_v';

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    /** @var array<string, array{0: int, 1: int}|null> */
    private array $sizes = [];

    /**
     * @param list<int> $widths
     * @param list<string> $ratios the ratios (as "16:9") any image may be asked in
     * @param bool|null $gdWebp whether GD can write WebP; null asks PHP (a test passes false: a PHP without GD cannot be had by a setting)
     */
    public function __construct(
        #[Autowire(param: 'itk_cms.upload_dir')]
        private readonly string $uploadDir,
        #[Autowire(param: 'itk_cms.upload_url')]
        private readonly string $uploadUrl,
        #[Autowire(param: 'itk_cms.images.widths')]
        private readonly array $widths,
        #[Autowire(param: 'itk_cms.images.quality')]
        private readonly int $quality,
        #[Autowire(param: 'itk_cms.images.max_pixels')]
        private readonly int $maxPixels,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire(param: 'itk_cms.images.ratios')]
        private readonly array $ratios = ['16:9', '4:3', '1:1', '3:4'],
        #[Autowire(param: 'itk_cms.images.avif_quality')]
        private readonly int $avifQuality = 60,
        private readonly ?bool $gdWebp = null,
    ) {
    }

    /**
     * Whether GD in this PHP can write WebP. Without GD (ext-gd is in require, but a server may ignore it) no copy is made: the addresses of copies are not handed out
     * and a request for one is sent to the original.
     */
    public function supportsWebp(): bool
    {
        return $this->gdWebp ?? \function_exists('imagewebp');
    }

    /** @return list<string> */
    public function ratios(): array
    {
        return $this->ratios;
    }

    public function isAllowedRatio(string $key): bool
    {
        return \in_array($key, $this->ratios, true);
    }

    /** Whether GD in this PHP can write AVIF. */
    public function supportsAvif(): bool
    {
        return \function_exists('imageavif');
    }

    /**
     * "16:9", "16x9" or "32 : 18" as the reduced pair [16, 9]; null when it is not a ratio (each side 1..99).
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parseRatio(mixed $ratio): ?array
    {
        if (!\is_string($ratio) || !\preg_match('~^\s*(\d{1,2})\s*[:xX/]\s*(\d{1,2})\s*$~', $ratio, $m) || (int) $m[1] < 1 || (int) $m[2] < 1) {
            return null;
        }
        $g = self::gcd((int) $m[1], (int) $m[2]);

        return [(int) $m[1] / $g, (int) $m[2] / $g];
    }

    /** "16:9" for [16, 9]. */
    public static function ratioKey(array $ratio): string
    {
        return $ratio[0] . ':' . $ratio[1];
    }

    /**
     * The ratio of a fixed slot in a layout (an <img> of $width × $height pixels) as a key such as "14:5": the reduced
     * fraction of width:height. A ratio takes sides of 1..99, so a slot that reduces to bigger numbers (1095×515 is 219:103)
     * gets the closest ratio that fits (17:8, off by well under 1%). Null for a size that is not positive.
     * Use it for media_url()/itk_img() so that the delivered file has the shape of its slot.
     */
    public static function slotRatio(int $width, int $height): ?string
    {
        if ($width < 1 || $height < 1) {
            return null;
        }
        $g = self::gcd($width, $height);
        $a = \intdiv($width, $g);
        $b = \intdiv($height, $g);
        if ($a <= 99 && $b <= 99) {
            return $a . ':' . $b;
        }
        $target = $width / $height;
        $best = [1, 1];
        $bestError = \INF;
        for ($x = 1; $x <= 99; ++$x) {
            for ($y = 1; $y <= 99; ++$y) {
                if (1 !== self::gcd($x, $y)) {
                    continue; // only reduced pairs: one address per ratio
                }
                $error = \abs($x / $y - $target) / $target;
                if ($error < $bestError - 1e-12 || (\abs($error - $bestError) <= 1e-12 && $x + $y < $best[0] + $best[1])) {
                    $best = [$x, $y];
                    $bestError = $error;
                }
            }
        }

        return $best[0] . ':' . $best[1];
    }

    /**
     * The frame of a picture for a ratio, in pixels: [x, y, width, height] inside an image of $ow × $oh. A saved frame wins
     * (cut down to the ratio around its centre); without one it is the biggest frame of the ratio that fits, put around the
     * focal point (the centre when there is none) and kept inside the picture.
     *
     * @param array{0: int, 1: int} $ratio
     * @param array{x?: mixed, y?: mixed, w?: mixed, h?: mixed}|null $crop fractions of the picture
     * @param array{x?: mixed, y?: mixed}|null $focal fractions of the picture
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function cropRect(int $ow, int $oh, array $ratio, ?array $crop = null, ?array $focal = null): array
    {
        [$a, $b] = $ratio;
        $f = static fn (mixed $v, float $default): float => \is_numeric($v) ? \max(0.0, \min(1.0, (float) $v)) : $default;
        if (null !== $crop && isset($crop['w'], $crop['h']) && $f($crop['w'], 0) > 0 && $f($crop['h'], 0) > 0) {
            $cw = $f($crop['w'], 1) * $ow;
            $ch = $f($crop['h'], 1) * $oh;
            $cx = $f($crop['x'] ?? 0, 0) * $ow;
            $cy = $f($crop['y'] ?? 0, 0) * $oh;
            $cx = \min($cx, $ow - $cw);
            $cy = \min($cy, $oh - $ch);
            // the frame was drawn by a person: cut it to the exact ratio, around its centre
            if ($cw * $b > $ch * $a) {
                $nw = $ch * $a / $b;
                $cx += ($cw - $nw) / 2;
                $cw = $nw;
            } else {
                $nh = $cw * $b / $a;
                $cy += ($ch - $nh) / 2;
                $ch = $nh;
            }
        } else {
            if ($ow * $b > $oh * $a) {
                $ch = (float) $oh;
                $cw = $oh * $a / $b;
            } else {
                $cw = (float) $ow;
                $ch = $ow * $b / $a;
            }
            $cx = \max(0.0, \min($ow - $cw, $f($focal['x'] ?? null, 0.5) * $ow - $cw / 2));
            $cy = \max(0.0, \min($oh - $ch, $f($focal['y'] ?? null, 0.5) * $oh - $ch / 2));
        }
        $w = \max(1, \min($ow, (int) \round($cw)));
        $h = \max(1, \min($oh, (int) \round($ch)));

        return [\max(0, \min($ow - $w, (int) \round($cx))), \max(0, \min($oh - $h, (int) \round($cy))), $w, $h];
    }

    private static function gcd(int $a, int $b): int
    {
        return 0 === $b ? $a : self::gcd($b, $a % $b);
    }

    /** @return list<int> */
    public function widths(): array
    {
        return $this->widths;
    }

    public function isAllowedWidth(int $width): bool
    {
        return \in_array($width, $this->widths, true);
    }

    /** Width of the original file of a media library image (as it looks), or null for any other address / unreadable file. */
    public function originalWidth(string $url): ?int
    {
        $rel = $this->relative($url);

        return null === $rel ? null : ($this->dimensions($this->originalPath($rel))[0] ?? null);
    }

    /** Whether the address is an image of the media library that gets variants. */
    public function isConvertible(string $url): bool
    {
        return null !== $this->relative($url);
    }

    /**
     * Address of the variant that is at least $width wide (the nearest allowed width; the largest one when $width is above all).
     * Anything that is not a convertible upload is returned as it is. With a ratio ("16:9") the address is the one of the frame of that ratio.
     *
     * @param array{0: int, 1: int}|null $originalSize stored dimensions, when both are known
     */
    public function url(string $originalUrl, int $width, ?string $ratio = null, string $format = 'webp', ?array $originalSize = null): string
    {
        $rel = $this->relative($originalUrl);
        if (null === $rel) {
            return $originalUrl;
        }
        $size = $originalSize ?? $this->dimensions($this->originalPath($rel));
        if (null !== $size && $size[0] * $size[1] > $this->maxPixels) {
            return $originalUrl;
        }
        $w = $this->snap($width);
        if (null !== $size) {
            $w = \min($w, $this->snap($size[0])); // a bigger variant would be the same picture
        }

        return $this->variantUrl($w, $rel, self::parseRatio($ratio), $format);
    }

    /**
     * Value of the srcset attribute: the allowed widths below the original (and the first one that covers it, labelled with
     * the real width), up to the first one that is at least 90% of $maxWidth (the widest picture the layout can show, in device pixels).
     * Empty for pass-through addresses.
     *
     * @param int|null $originalWidth width from the media library; read from the file when null
     * @param string|null $ratio the frame of that ratio ("16:9"); null: the whole picture
     * @param string $format 'webp' or 'avif'
     */
    public function srcset(string $originalUrl, ?int $maxWidth = null, ?int $originalWidth = null, ?string $ratio = null, string $format = 'webp'): string
    {
        $rel = $this->relative($originalUrl);
        if (null === $rel) {
            return '';
        }
        $size = $this->dimensions($this->originalPath($rel));
        if (null !== $size && $size[0] * $size[1] > $this->maxPixels) {
            return '';
        }
        $orig = $originalWidth ?: ($size[0] ?? 0);
        $parsed = self::parseRatio($ratio);
        $parts = [];
        foreach ($this->candidates($orig, $maxWidth) as [$w, $descriptor]) {
            $parts[] = $this->variantUrl($w, $rel, $parsed, $format) . ' ' . $descriptor . 'w';
        }

        return \implode(', ', $parts);
    }

    /**
     * The allowed widths that srcset() uses for an original of the given width: [file width, label].
     *
     * @return list<array{0: int, 1: int}>
     */
    public function candidates(int $originalWidth, ?int $maxWidth = null): array
    {
        $out = [];
        foreach ($this->widths as $w) {
            if ($originalWidth > 0 && $w >= $originalWidth) {
                $out[] = [$w, $originalWidth];
                break;
            }
            $out[] = [$w, $w];
            if (null !== $maxWidth && $w >= $maxWidth * 0.9) {
                break;
            }
        }

        return $out;
    }

    /**
     * Rewrites <img src="/uploads/…" alt="…" [width="W" height="H"] [data-align]> of sanitized rich text to a variant with srcset (render time, never stored).
     *
     * Without width/height the picture is laid out by the page's CSS: src is the $srcWidth variant, sizes is $sizes.
     * With them (the display size the author set) the picture is shown W px wide at most, so: the attributes stay on the tag (the
     * browser reserves the box, no layout jump), src is the smallest variant that covers W, srcset goes up to 2×W (retina) and
     * sizes is "(max-width: Wpx) 100vw, Wpx". Never upscaled: when the original is narrower than W, W and H shrink in proportion.
     */
    public function rewriteHtml(string $html, int $srcWidth = 960, int $maxWidth = 1920, string $sizes = '(max-width: 800px) 100vw, 800px'): string
    {
        if (!\str_contains($html, '<img ')) {
            return $html;
        }

        return (string) \preg_replace_callback('~<img src="([^"]*)" alt="([^"]*)"(?: width="(\d+)")?(?: height="(\d+)")?( data-align="[a-z]+")?>~', function (array $m) use ($srcWidth, $maxWidth, $sizes): string {
            $src = \html_entity_decode($m[1], \ENT_QUOTES);
            $width = (int) ($m[3] ?? 0);
            $height = (int) ($m[4] ?? 0);
            $rel = $this->relative($src);
            $dims = null !== $rel ? $this->dimensions($this->originalPath($rel)) : null;
            if ($width > 0 && null !== $dims && $width > $dims[0]) {
                $height = $height > 0 ? \max(1, (int) \round($height * $dims[0] / $width)) : 0;
                $width = $dims[0];
            }
            $own = $width > 0;
            $srcset = $this->srcset($src, $own ? $width * 2 : $maxWidth);
            if ('' === $srcset) {
                return $m[0];
            }
            $e = static fn (string $s): string => \htmlspecialchars($s, \ENT_QUOTES);
            $size = ($own ? ' width="' . $width . '"' : '') . ($own && $height > 0 ? ' height="' . $height . '"' : '');

            return '<img src="' . $e($this->url($src, $own ? $width : $srcWidth)) . '" srcset="' . $e($srcset) . '" sizes="' . $e($own ? \sprintf('(max-width: %dpx) 100vw, %dpx', $width, $width) : $sizes) . '" alt="' . $m[2] . '"' . $size . ($m[5] ?? '') . ' loading="lazy" decoding="async">';
        }, $html);
    }

    /**
     * Size of an image as it looks (the EXIF turn of a JPEG is applied); null when the file is not a readable image.
     *
     * @return array{0: int, 1: int}|null width and height
     */
    public function dimensions(string $path): ?array
    {
        if (\array_key_exists($path, $this->sizes)) {
            return $this->sizes[$path];
        }
        $info = \is_file($path) ? @\getimagesize($path) : false;
        if (!\is_array($info) || $info[0] < 1 || $info[1] < 1) {
            return $this->sizes[$path] = null;
        }
        $size = [(int) $info[0], (int) $info[1]];
        if (\IMAGETYPE_JPEG === $info[2] && $this->orientation($path) >= 5) {
            $size = [$size[1], $size[0]];
        }

        return $this->sizes[$path] = $size;
    }

    /**
     * Absolute path of the original behind a variant address (the part after w{width}/; .webp or .avif), strictly inside the upload dir; null when there is none.
     *
     * @return array{0: string, 1: string}|null path and its path under the upload dir
     */
    public function resolveOriginal(string $variantRel): ?array
    {
        if (!\preg_match('~^(.+)\.(?:webp|avif)$~i', $variantRel, $m) || !$this->safeRel($variantRel) || \str_starts_with($variantRel, self::DIR . '/')) {
            return null;
        }
        $root = \realpath($this->uploadDir);
        if (false === $root) {
            return null;
        }
        foreach ([...self::EXTENSIONS, ...\array_map('strtoupper', self::EXTENSIONS)] as $ext) {
            $real = \realpath($root . '/' . $m[1] . '.' . $ext);
            if (false !== $real && \str_starts_with($real, $root . '/') && \is_file($real)) {
                return [$real, \substr($real, \strlen($root) + 1)];
            }
        }

        return null;
    }

    /**
     * Where the variant file lives (it may not exist yet).
     *
     * @param array{0: int, 1: int}|null $ratio
     */
    public function variantPath(int $width, string $variantRel, ?array $ratio = null): string
    {
        return \rtrim($this->uploadDir, '/') . '/' . self::DIR . '/' . self::dirName($width, $ratio) . '/' . $variantRel;
    }

    /** @param array{0: int, 1: int}|null $ratio */
    public static function dirName(int $width, ?array $ratio = null): string
    {
        return 'w' . $width . (null === $ratio ? '' : '-r' . $ratio[0] . 'x' . $ratio[1]);
    }

    /**
     * Makes the variant when it is missing and returns its path; null when the original cannot be converted
     * (too many pixels, animated, unreadable, not enough memory, AVIF without GD support): the caller then serves the original.
     * The format is the extension of $variantRel (.webp or .avif). With a ratio the variant is the frame of that ratio (see cropRect()).
     *
     * @param array{0: int, 1: int}|null $ratio
     * @param array<string, mixed>|null $crop the saved frame of that ratio (fractions of the picture)
     * @param array<string, mixed>|null $focal
     */
    public function ensure(string $originalPath, int $width, string $variantRel, ?array $ratio = null, ?array $crop = null, ?array $focal = null): ?string
    {
        $out = $this->variantPath($width, $variantRel, $ratio);
        if (\is_file($out)) {
            return $out;
        }
        $format = 'avif' === \strtolower(\pathinfo($variantRel, \PATHINFO_EXTENSION)) ? 'avif' : 'webp';
        if (!$this->supportsWebp() || ('avif' === $format && !$this->supportsAvif())) {
            return null;
        }
        $info = @\getimagesize($originalPath);
        if (!\is_array($info)) {
            return null;
        }
        [$sw, $sh, $type] = $info;
        if ($sw * $sh > $this->maxPixels) {
            $this->logger?->warning('Image variant skipped: too many pixels, the original is served', ['file' => $originalPath, 'pixels' => $sw * $sh, 'limit' => $this->maxPixels]);

            return null;
        }
        if (!$this->fitsMemory($sw * $sh * (null === $ratio ? 1 : 2))) {
            $this->logger?->warning('Image variant skipped: not enough memory, the original is served', ['file' => $originalPath, 'pixels' => $sw * $sh]);

            return null;
        }
        if (\IMAGETYPE_WEBP === $type && $this->isAnimatedWebp($originalPath)) {
            return null;
        }
        $src = $this->load($originalPath, $type);
        if (null === $src) {
            return null;
        }
        // GD images are freed as soon as they leave this method (every return below)
        if (!\imageistruecolor($src)) {
            \imagepalettetotruecolor($src);
        }
        $orientation = \IMAGETYPE_JPEG === $type ? $this->orientation($originalPath) : 1;
        if (null !== $ratio) {
            $dst = $this->frame($src, $orientation, $width, $ratio, $crop, $focal);
        } else {
            $dst = $this->scaled($src, $orientation, $sw, $sh, $width);
        }
        unset($src);
        if (null === $dst) {
            return null;
        }
        $dir = \dirname($out);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return null;
        }
        $tmp = $dir . '/.tmp-' . \bin2hex(\random_bytes(6));
        $written = 'avif' === $format ? @\imageavif($dst, $tmp, $this->avifQuality) : \imagewebp($dst, $tmp, $this->quality);
        if (!$written) {
            @\unlink($tmp);

            return null;
        }
        @\chmod($tmp, 0644);
        if (!@\rename($tmp, $out)) {
            @\unlink($tmp);

            return null;
        }

        return $out;
    }

    /** The whole picture at the given width, turned as its EXIF says. */
    private function scaled(\GdImage $src, int $orientation, int $sw, int $sh, int $width): ?\GdImage
    {
        $swap = $orientation >= 5;
        // size as it looks after the turn
        $ow = $swap ? $sh : $sw;
        $oh = $swap ? $sw : $sh;
        $tw = \min($width, $ow);
        $th = \max(1, (int) \round($oh * $tw / $ow));
        // the resize happens in the file's own orientation, the (small) result is turned afterwards
        $dw = $swap ? $th : $tw;
        $dh = $swap ? $tw : $th;
        $dst = $this->canvas($dw, $dh);
        if (null === $dst) {
            return null;
        }
        \imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);

        return $this->turn($dst, $orientation);
    }

    /**
     * A frame of the picture: the picture is turned first (the frame is drawn on it as it looks), then the frame is cut out and scaled to $width.
     *
     * @param array{0: int, 1: int} $ratio
     * @param array<string, mixed>|null $crop
     * @param array<string, mixed>|null $focal
     */
    private function frame(\GdImage $src, int $orientation, int $width, array $ratio, ?array $crop, ?array $focal): ?\GdImage
    {
        $src = $this->turn($src, $orientation);
        [$x, $y, $cw, $ch] = self::cropRect(\imagesx($src), \imagesy($src), $ratio, $crop, $focal);
        $tw = \min($width, $cw);
        $th = \max(1, (int) \round($tw * $ratio[1] / $ratio[0]));
        $dst = $this->canvas($tw, $th);
        if (null === $dst) {
            return null;
        }
        \imagecopyresampled($dst, $src, 0, 0, $x, $y, $tw, $th, $cw, $ch);

        return $dst;
    }

    private function canvas(int $w, int $h): ?\GdImage
    {
        $dst = \imagecreatetruecolor($w, $h);
        if (false === $dst) {
            return null;
        }
        \imagealphablending($dst, false);
        \imagesavealpha($dst, true);
        \imagefill($dst, 0, 0, (int) \imagecolorallocatealpha($dst, 0, 0, 0, 127));

        return $dst;
    }

    /** Deletes every variant of an original (when the file leaves the media library). */
    public function remove(string $originalUrl): void
    {
        $this->removeDirs($originalUrl, '~^w\d+(?:-r\d+x\d+)?$~');
    }

    /** Deletes the variants that are frames of a ratio (when the focal point or a frame of the file changes). */
    public function removeRatioVariants(string $originalUrl): void
    {
        $this->removeDirs($originalUrl, '~^w\d+-r\d+x\d+$~');
    }

    private function removeDirs(string $originalUrl, string $dirPattern): void
    {
        $rel = $this->relative($originalUrl);
        $base = \rtrim($this->uploadDir, '/') . '/' . self::DIR;
        if (null === $rel || !\is_dir($base)) {
            return;
        }
        foreach (new \DirectoryIterator($base) as $dir) {
            if ($dir->isDot() || !$dir->isDir() || !\preg_match($dirPattern, $dir->getFilename())) {
                continue;
            }
            foreach (['webp', 'avif'] as $format) {
                $file = $dir->getPathname() . '/' . $this->variantRel($rel, $format);
                if (\is_file($file)) {
                    @\unlink($file);
                }
            }
        }
    }

    /**
     * Where the variant of an original with this exact (allowed) width lives; null for pass-through addresses.
     *
     * @param array{0: int, 1: int}|null $ratio
     */
    public function variantFileOf(string $originalUrl, int $width, ?array $ratio = null, string $format = 'webp'): ?string
    {
        $rel = $this->relative($originalUrl);

        return null === $rel ? null : $this->variantPath($width, $this->variantRel($rel, $format), $ratio);
    }

    /**
     * Makes the variant of an original address (see ensure()); null for pass-through addresses and originals that cannot be converted.
     *
     * @param array{0: int, 1: int}|null $ratio
     * @param array<string, mixed>|null $crop
     * @param array<string, mixed>|null $focal
     */
    public function generate(string $originalUrl, int $width, string $format = 'webp', ?array $ratio = null, ?array $crop = null, ?array $focal = null): ?string
    {
        $rel = $this->relative($originalUrl);

        return null === $rel ? null : $this->ensure($this->originalPath($rel), $width, $this->variantRel($rel, $format), $ratio, $crop, $focal);
    }

    /**
     * Makes every copy an original of this width needs (the widths of srcset(), WebP and, when GD can, AVIF); the ones that exist are kept.
     *
     * @param list<int> $widths explicit variant widths, or [] for the original's srcset widths
     *
     * @return array{made: int, existing: int, skipped: int}
     */
    public function warm(string $originalUrl, ?int $originalWidth = null, array $widths = []): array
    {
        $out = ['made' => 0, 'existing' => 0, 'skipped' => 0];
        $path = $this->pathOf($originalUrl);
        if (null === $path) {
            return $out;
        }
        if ([] === $widths && !$originalWidth) {
            $known = \array_key_exists($path, $this->sizes);
            $originalWidth = $this->dimensions($path)[0] ?? 0;
            if (!$known) {
                unset($this->sizes[$path]);
            }
        }
        $jobs = [] === $widths ? $this->warmJobs($originalWidth ?? 0) : $this->jobsForWidths($widths);
        foreach ($jobs as [$w, $format]) {
            if (\is_file((string) $this->variantFileOf($originalUrl, $w, null, $format))) {
                ++$out['existing'];
            } elseif (null !== $this->generate($originalUrl, $w, $format)) {
                ++$out['made'];
            } else {
                ++$out['skipped'];
            }
        }

        return $out;
    }

    /**
     * The copies warm() makes for an original of this width: [width, format].
     *
     * @return list<array{0: int, 1: string}>
     */
    public function warmJobs(int $originalWidth): array
    {
        return $this->jobsForWidths(\array_column($this->candidates($originalWidth), 0));
    }

    /**
     * @param list<int> $widths
     *
     * @return list<array{0: int, 1: string}>
     */
    private function jobsForWidths(array $widths): array
    {
        $jobs = [];
        foreach ($widths as $w) {
            $jobs[] = [$w, 'webp'];
            if ($this->supportsAvif()) {
                $jobs[] = [$w, 'avif'];
            }
        }

        return $jobs;
    }

    /** Absolute path of an original by its address under the upload dir; null for other addresses. */
    public function pathOf(string $originalUrl): ?string
    {
        $rel = $this->relative($originalUrl);

        return null === $rel ? null : $this->originalPath($rel);
    }

    /** Path under the upload dir of an address that gets variants, else null. */
    private function relative(string $url): ?string
    {
        $prefix = \rtrim($this->uploadUrl, '/') . '/';
        if (!\str_starts_with($url, $prefix) || !$this->supportsWebp()) {
            return null;
        }
        $rel = \substr($url, \strlen($prefix));
        if (!$this->safeRel($rel) || \str_starts_with($rel, self::DIR . '/') || \str_contains($rel, '?') || \str_contains($rel, '#')) {
            return null;
        }
        $ext = \strtolower(\pathinfo($rel, \PATHINFO_EXTENSION));
        if (!\in_array($ext, self::EXTENSIONS, true) || ('avif' === $ext && !\function_exists('imagecreatefromavif'))) {
            return null;
        }

        return $rel;
    }

    private function safeRel(string $rel): bool
    {
        return '' !== $rel && !\str_starts_with($rel, '/') && !\str_contains($rel, '..') && !\str_contains($rel, '//')
            && !\str_contains($rel, "\0") && !\str_contains($rel, '\\');
    }

    private function originalPath(string $rel): string
    {
        return \rtrim($this->uploadDir, '/') . '/' . $rel;
    }

    /** The name of a variant: the original's with the extension of the format. */
    private function variantRel(string $rel, string $format = 'webp'): string
    {
        return (string) \preg_replace('~\.[^./]+$~', 'avif' === $format ? '.avif' : '.webp', $rel);
    }

    /** @param array{0: int, 1: int}|null $ratio */
    private function variantUrl(int $width, string $rel, ?array $ratio = null, string $format = 'webp'): string
    {
        return \rtrim($this->uploadUrl, '/') . '/' . self::DIR . '/' . self::dirName($width, $ratio) . '/' . $this->variantRel($rel, $format);
    }

    /** The smallest allowed width that is not below $width (the largest allowed one when none is). */
    private function snap(int $width): int
    {
        foreach ($this->widths as $w) {
            if ($w >= $width) {
                return $w;
            }
        }

        return $this->widths[\count($this->widths) - 1];
    }

    private function load(string $path, int $type): ?\GdImage
    {
        $img = match ($type) {
            \IMAGETYPE_JPEG => @\imagecreatefromjpeg($path),
            \IMAGETYPE_PNG => @\imagecreatefrompng($path),
            \IMAGETYPE_WEBP => @\imagecreatefromwebp($path),
            \IMAGETYPE_AVIF => \function_exists('imagecreatefromavif') ? @\imagecreatefromavif($path) : false,
            default => false,
        };

        return $img instanceof \GdImage ? $img : null;
    }

    private function orientation(string $path): int
    {
        if (!\function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @\exif_read_data($path, 'IFD0');

        return \is_array($exif) ? \max(1, \min(8, (int) ($exif['Orientation'] ?? 1))) : 1;
    }

    /** Applies the EXIF orientation (1-8) to an already resized image; may return a new image. */
    private function turn(\GdImage $img, int $orientation): \GdImage
    {
        $turned = static function (\GdImage $im, int $angle): \GdImage {
            return \imagerotate($im, $angle, 0) ?: $im;
        };

        switch ($orientation) {
            case 2:
                \imageflip($img, \IMG_FLIP_HORIZONTAL);

                return $img;
            case 3:
                return $turned($img, 180);
            case 4:
                \imageflip($img, \IMG_FLIP_VERTICAL);

                return $img;
            case 5:
            case 7:
                $out = $turned($img, 5 === $orientation ? -90 : 90);
                \imageflip($out, \IMG_FLIP_HORIZONTAL);

                return $out;
            case 6:
                return $turned($img, -90);
            case 8:
                return $turned($img, 90);
            default:
                return $img;
        }
    }

    private function isAnimatedWebp(string $path): bool
    {
        $head = (string) @\file_get_contents($path, false, null, 0, 32);

        return 'VP8X' === \substr($head, 12, 4) && (\ord($head[20] ?? "\0") & 0x02) === 0x02;
    }

    /** Whether a decoded image of this many pixels (4 bytes each, plus working copies) fits into what is left of memory_limit. */
    private function fitsMemory(int $pixels): bool
    {
        $limit = \trim((string) \ini_get('memory_limit'));
        if ('' === $limit || '-1' === $limit) {
            return true;
        }
        $bytes = (int) $limit;
        $bytes *= match (\strtolower(\substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return \memory_get_usage() + $pixels * 5 < $bytes;
    }
}
