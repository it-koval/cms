<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Command\WarmMediaCommand;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Media\MediaService;
use Itk\Cms\Repository\MediaFileRepository;
use Itk\Cms\Repository\MediaFolderRepository;
use Itk\Cms\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

final class LegacyExifDimensionsTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!\function_exists('exif_read_data')) {
            self::markTestSkipped('EXIF extension required for orientation regression.');
        }
        $this->dir = __DIR__ . '/../App/var/legacy-exif-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0700, true);
        $image = \imagecreatetruecolor(160, 1200);
        \imagejpeg($image, $this->dir . '/portrait.jpg', 85);
        $jpeg = \file_get_contents($this->dir . '/portrait.jpg');
        $exif = "Exif\0\0" . 'MM' . "\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00\x00\x00\x00\x00";
        \file_put_contents($this->dir . '/portrait.jpg', \substr($jpeg, 0, 2) . "\xFF\xE1" . \pack('n', \strlen($exif) + 2) . $exif . \substr($jpeg, 2));
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? \rmdir($file->getPathname()) : \unlink($file->getPathname());
            }
            \rmdir($this->dir);
        }
        parent::tearDown();
    }

    #[Test]
    public function warmUsesTheFilesVisualWidthEvenWhenStoredWidthPredatesExifSupport(): void
    {
        $variants = new ImageVariants($this->dir, '/uploads/media', [320, 640, 960, 1280], 82, 50_000_000);
        self::assertSame([1200, 160], $variants->dimensions($this->dir . '/portrait.jpg'));
        $variants->warm('/uploads/media/portrait.jpg', 160);
        foreach ([320, 640, 960, 1280] as $width) {
            self::assertFileExists($variants->variantFileOf('/uploads/media/portrait.jpg', $width));
        }
        self::assertSame([960, 128], \array_slice(\getimagesize($variants->variantFileOf('/uploads/media/portrait.jpg', 960)), 0, 2));
        self::assertSame(0, $variants->warm('/uploads/media/portrait.jpg', 160)['made']);
    }

    #[Test]
    public function theWarmCommandUsesActualExifWidthFromLegacyMetadata(): void
    {
        $variants = new ImageVariants($this->dir, '/uploads/media', [320, 640, 960, 1280], 82, 50_000_000);
        $files = $this->createStub(MediaFileRepository::class);
        $files->method('imagesForWarming')->willReturn([['path' => '/uploads/media/portrait.jpg', 'width' => 160]]);
        $tester = new CommandTester(new WarmMediaCommand($files, $variants));
        self::assertSame(0, $tester->execute([], ['interactive' => false]));
        foreach ([320, 640, 960, 1280] as $width) {
            self::assertFileExists($variants->variantFileOf('/uploads/media/portrait.jpg', $width));
        }
    }

    #[Test]
    public function aLegacyJpegThumbnailAndPresentedDimensionsMatchTheActualOrientation(): void
    {
        self::bootKernel();
        $variants = new ImageVariants($this->dir, '/uploads/media', [160, 320, 640, 960, 1280], 82, 50_000_000);
        $service = new MediaService($this->createStub(EntityManagerInterface::class), $this->createStub(MediaFileRepository::class), $this->createStub(Connection::class), self::getContainer()->get(Schema::class), $variants, $this->createStub(MediaFolderRepository::class), $this->createStub(MessageBusInterface::class), $this->dir, '/uploads/media', 10);
        foreach ([[160, 1200], [1200, 160]] as [$width, $height]) {
            $file = new MediaFile('/uploads/media/portrait.jpg', 'Portrait', 'image/jpeg');
            $file->width = $width;
            $file->height = $height;
            $row = $service->present($file);
            self::assertSame('/uploads/media/_v/w320/portrait.webp', $row['thumb']);
            self::assertSame([1200, 160], [$row['w'], $row['h']]);
        }
    }
}
