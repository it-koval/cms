<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Media\MediaService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WarmMediaCommandTest extends KernelTestCase
{
    #[Test]
    public function warmsThroughMultipleBatchesAndKeepsExistingCopies(): void
    {
        $kernel = self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $media = $container->get(MediaService::class);
        $variants = $container->get(ImageVariants::class);
        $key = 'warm-' . \bin2hex(\random_bytes(5));
        $filename = $kernel->getProjectDir() . '/' . $key . '.png';
        $file = null;
        try {
            for ($i = 0; $i < 501; ++$i) {
                $em->persist(new MediaFile('/uploads/media/' . $key . '-' . $i . '.png', $key, 'image/png'));
            }
            $em->flush();
            self::assertTrue(\imagepng(\imagecreatetruecolor(24, 18), $filename));
            $file = $media->upload(new UploadedFile($filename, $key . '.png', 'image/png', null, true));
            $em->clear();

            $tester = new CommandTester((new Application($kernel))->find('itk:media:warm'));
            $options = ['--width' => ['320']];
            self::assertSame(0, $tester->execute($options, ['interactive' => false]));
            $webp = $variants->variantFileOf($file->path, 320);
            self::assertNotNull($webp);
            self::assertFileExists($webp);
            self::assertTrue(\touch($webp, 1700000000));
            self::assertSame(0, $tester->execute($options, ['interactive' => false]));
            \clearstatcache(true, $webp);
            self::assertSame(1700000000, \filemtime($webp));
            self::assertCount(0, $em->getUnitOfWork()->getIdentityMap()[MediaFile::class] ?? []);
        } finally {
            if (null !== $file) {
                $media->remove($file->id);
            }
            if (\is_file($filename)) {
                \unlink($filename);
            }
            $em->getConnection()->executeStatement('DELETE FROM itk_media_file WHERE original_name = ?', [$key]);
        }
    }
}
