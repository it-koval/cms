<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Controller\Site\ImageVariantController;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Media\MediaService;
use Itk\Cms\Repository\MediaFileRepository;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Маршрут копий /uploads/media/_v/w{ширина}/…: белый список ширин, обход каталогов, создание файла, загрузка и удаление. */
final class ImageVariantRouteTest extends WebTestCase
{
    private KernelBrowser $client;
    private MediaService $media;
    private ?MediaFile $file = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->media = self::getContainer()->get(MediaService::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->file) {
            try {
                $this->media->remove($this->file->id);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    private function upload(): MediaFile
    {
        $img = \imagecreatetruecolor(1500, 900);
        for ($i = 0; $i < 300; ++$i) {
            \imagefilledellipse($img, \random_int(0, 1500), \random_int(0, 900), \random_int(10, 200), \random_int(10, 200), (int) \imagecolorallocate($img, \random_int(0, 255), \random_int(0, 255), \random_int(0, 255)));
        }
        $tmp = \sys_get_temp_dir() . '/itk-route-' . \bin2hex(\random_bytes(4)) . '.jpg';
        \imagejpeg($img, $tmp, 95);

        return $this->file = $this->media->upload(new UploadedFile($tmp, 'route-test.jpg', 'image/jpeg', null, true));
    }

    private function variantUrl(MediaFile $m, int $w): string
    {
        return self::getContainer()->get(ImageVariants::class)->url($m->path, $w);
    }

    #[Test]
    public function aMissingVariantIsMadeOnFirstRequestAndKeptAsAFile(): void
    {
        $m = $this->upload();
        $url = $this->variantUrl($m, 640);
        $file = self::getContainer()->getParameter('kernel.project_dir') . '/public' . $url;
        self::assertFileDoesNotExist($file);

        $this->client->request('GET', $url);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        self::assertFileExists($file);
        self::assertSame(640, \getimagesize($file)[0]);
        self::assertLessThan($m->size, \filesize($file));
    }

    #[Test]
    public function withoutGdTheRouteRedirectsToTheOriginalInsteadOfFailing(): void
    {
        $m = $this->upload();
        $container = self::getContainer();
        $noGd = new ImageVariants(
            $container->getParameter('itk_cms.upload_dir'),
            $container->getParameter('itk_cms.upload_url'),
            $container->getParameter('itk_cms.images.widths'),
            $container->getParameter('itk_cms.images.quality'),
            $container->getParameter('itk_cms.images.max_pixels'),
            gdWebp: false,
        );
        $controller = new ImageVariantController($noGd, $container->get(MediaFileRepository::class), $container->getParameter('itk_cms.upload_url'));

        $response = $controller(640, \substr($this->variantUrl($m, 640), \strlen('/uploads/media/_v/w640/')));

        self::assertSame(302, $response->getStatusCode(), 'not a 500');
        self::assertSame($m->path, $response->headers->get('Location'));
        self::assertFileDoesNotExist($container->getParameter('kernel.project_dir') . '/public' . $this->variantUrl($m, 640));
    }

    #[Test]
    public function aWidthOutsideTheWhitelistIsNotFound(): void
    {
        $m = $this->upload();
        $url = \str_replace('/w320/', '/w321/', $this->variantUrl($m, 320));
        $this->client->request('GET', $url);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertFileDoesNotExist(self::getContainer()->getParameter('kernel.project_dir') . '/public' . $url);
        $this->client->request('GET', \str_replace('/w320/', '/w99999/', $this->variantUrl($m, 320)));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function pathTraversalAndUnknownFilesAreNotFound(): void
    {
        foreach ([
            '/uploads/media/_v/w640/../../../composer.webp',
            '/uploads/media/_v/w640/%2e%2e/%2e%2e/%2e%2e/composer.webp',
            '/uploads/media/_v/w640/2026/10/..%2f..%2f..%2f..%2fcomposer.webp',
            '/uploads/media/_v/w640/2026/10/nothing-here.webp',
            '/uploads/media/_v/w640/2026/10/nothing-here.jpg',
        ] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $url);
        }
    }

    #[Test]
    public function theLibraryItemHasAThumbAndRemovingTheFileRemovesItsVariants(): void
    {
        $m = $this->upload();
        self::assertSame([1500, 900], [$m->width, $m->height]);
        self::assertSame($this->variantUrl($m, 320), $this->media->present($m)['thumb']);
        $root = self::getContainer()->getParameter('kernel.project_dir') . '/public';
        $this->client->request('GET', $this->variantUrl($m, 320));
        $this->client->request('GET', $this->variantUrl($m, 960));
        $files = [$root . $this->variantUrl($m, 320), $root . $this->variantUrl($m, 960)];
        foreach ($files as $f) {
            self::assertFileExists($f);
        }
        $this->media->remove($m->id);
        $this->file = null;
        foreach ($files as $f) {
            self::assertFileDoesNotExist($f);
        }
        self::assertFileDoesNotExist($root . $m->path);
    }
}
