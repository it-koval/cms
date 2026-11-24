<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/** itk_media_ratio() и ratio у itk_media_url / itk_media_srcset / itk_img: место фиксированного размера, запрещённая пропорция не ломает страницу. */
final class MediaRatioTwigTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return \trim(self::getContainer()->get(Environment::class)->createTemplate($template)->render());
    }

    #[Test]
    public function mediaRatioGivesTheKeyOfASlot(): void
    {
        self::assertSame('14:5|1:1|17:8', $this->render('{{ itk_media_ratio(700, 250) }}|{{ itk_media_ratio(245, 245) }}|{{ itk_media_ratio(1095, 515) }}'));
        self::assertSame('', $this->render('{{ itk_media_ratio(0, 10) }}'));
    }

    #[Test]
    public function allowedRatioMakesRatioAddresses(): void
    {
        $out = $this->render("{{ itk_media_url('/uploads/media/2026/10/x.jpg', 640, itk_media_ratio(700, 250)) }}");
        self::assertSame('/uploads/media/_v/w640-r14x5/2026/10/x.webp', $out);
        $srcset = $this->render("{{ itk_media_srcset('/uploads/media/2026/10/x.jpg', 1400, itk_media_ratio(700, 250)) }}");
        self::assertStringContainsString('-r14x5/', $srcset);
        self::assertStringNotContainsString('/_v/w320/', $srcset);
    }

    #[Test]
    public function notAllowedRatioFallsBackToTheWholePicture(): void
    {
        self::assertSame('/uploads/media/_v/w640/2026/10/x.webp', $this->render("{{ itk_media_url('/uploads/media/2026/10/x.jpg', 640, '11:7') }}"));
        self::assertStringNotContainsString('-r', $this->render("{{ itk_media_srcset('/uploads/media/2026/10/x.jpg', 1400, '11:7') }}"));
        self::assertStringNotContainsString('-r', $this->render("{{ itk_img('/uploads/media/2026/10/x.jpg', {width: 650, ratio: '11:7'}) }}"));
        // not a ratio at all
        self::assertSame('/uploads/media/_v/w640/2026/10/x.webp', $this->render("{{ itk_media_url('/uploads/media/2026/10/x.jpg', 640, 'wide') }}"));
    }
}
