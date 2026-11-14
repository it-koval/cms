<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Санитайзер текста статьи: width/height у картинок (положительные целые) сохраняются; блок после текста в заголовке — новая строка. */
final class RichTextImageSizeTest extends KernelTestCase
{
    private function sanitizer(): HtmlSanitizer
    {
        self::bootKernel();

        return self::getContainer()->get(HtmlSanitizer::class);
    }

    /** @return iterable<string, array{string, string}> */
    public static function images(): iterable
    {
        yield 'both' => ['<img src="/uploads/media/a.jpg" alt="A" width="399" height="384">', '<img src="/uploads/media/a.jpg" alt="A" width="399" height="384">'];
        yield 'width only' => ['<img src="/uploads/media/a.jpg" alt="" width="650">', '<img src="/uploads/media/a.jpg" alt="" width="650">'];
        yield 'attribute order is free' => ['<img height="10" width="20" alt="x" src="/uploads/media/a.jpg">', '<img src="/uploads/media/a.jpg" alt="x" width="20" height="10">'];
        yield 'alignment' => ['<img src="/uploads/media/a.jpg" alt="" width="9" data-align="Center">', '<img src="/uploads/media/a.jpg" alt="" width="9" data-align="center">'];
        yield 'unknown alignment is dropped' => ['<img src="/uploads/media/a.jpg" alt="" data-align="x&quot; onerror=&quot;y">', '<img src="/uploads/media/a.jpg" alt="">'];
        yield 'junk is dropped' => ['<img src="/uploads/media/a.jpg" alt="" width="100%" height="auto">', '<img src="/uploads/media/a.jpg" alt="">'];
        yield 'zero, negative, huge' => ['<img src="/uploads/media/a.jpg" alt="" width="0" height="-5">', '<img src="/uploads/media/a.jpg" alt="">'];
        yield 'script in the attribute' => ['<img src="/uploads/media/a.jpg" alt="" width="1&quot; onerror=&quot;x">', '<img src="/uploads/media/a.jpg" alt="">'];
        yield 'style and class are still dropped' => ['<img src="/uploads/media/a.jpg" alt="" width="5" style="margin:0" class="x" onerror="x">', '<img src="/uploads/media/a.jpg" alt="" width="5">'];
    }

    #[Test]
    #[DataProvider('images')]
    public function articleImageKeepsOnlyNumericSizes(string $in, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer()->sanitize($in, 'article'));
    }

    #[Test]
    public function imageInsideTextAndIdempotence(): void
    {
        $once = $this->sanitizer()->sanitize('<p>a <img src="/uploads/media/a.jpg" alt="" width="40" height="30"> b</p>', 'article');
        self::assertSame('<p>a <img src="/uploads/media/a.jpg" alt="" width="40" height="30"> b</p>', $once);
        self::assertSame($once, $this->sanitizer()->sanitize($once, 'article'));
    }

    #[Test]
    public function otherModesStillHaveNoImages(): void
    {
        self::assertSame('<p>t</p>', $this->sanitizer()->sanitize('<p>t</p><img src="/uploads/media/a.jpg" alt="" width="5">', 'rich'));
    }

    #[Test]
    public function aBlockAfterTextInAHeadlineStartsANewLine(): void
    {
        $s = $this->sanitizer();
        self::assertSame('Работаем с понедельника<br>по субботу', $s->sanitize('Работаем с понедельника<div>по субботу</div>', 'headline'));
        self::assertSame('a<br>b', $s->sanitize('<p>a</p><p>b</p>', 'headline'), 'a second block after a block gets no extra break');
        self::assertSame('a<br>b', $s->sanitize('a<br><div>b</div>', 'headline'), 'a break before the block is enough');
        self::assertSame('  b', $s->sanitize('  <div>b</div>', 'headline'), 'nothing but white space before: no break');
    }
}
