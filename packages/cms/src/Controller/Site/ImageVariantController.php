<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Site;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Repository\MediaFileRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A miss on {upload_url}/_v/w{width}/Y/m/name.webp (or .avif) or on {upload_url}/_v/w{width}-r{A}x{B}/Y/m/name.webp: makes the resized copy,
 * keeps it as a static file (the next request never reaches PHP) and returns it. A width outside itk_cms.images.widths, a ratio that is
 * neither in itk_cms.images.ratios nor a frame of the file, or an original that is not there is 404;
 * an original that cannot be converted (too many pixels, animated) is a temporary redirect to the original.
 * A ratio address is the frame saved for the file (MediaFile::$crops), else the part of the picture around its focal point.
 *
 * @internal
 */
#[AsController]
final class ImageVariantController
{
    public function __construct(
        private readonly ImageVariants $variants,
        private readonly MediaFileRepository $files,
        #[Autowire(param: 'itk_cms.upload_url')]
        private readonly string $uploadUrl,
    ) {
    }

    #[Route('%itk_cms.upload_url%/_v/w{width}/{path}', name: 'itk_image_variant', requirements: ['width' => '\d{1,5}', 'path' => '.+'], methods: ['GET', 'HEAD'])]
    #[Route('%itk_cms.upload_url%/_v/w{width}-r{a}x{b}/{path}', name: 'itk_image_variant_ratio', requirements: ['width' => '\d{1,5}', 'a' => '\d{1,2}', 'b' => '\d{1,2}', 'path' => '.+'], methods: ['GET', 'HEAD'])]
    public function __invoke(int $width, string $path, ?int $a = null, ?int $b = null): Response
    {
        if (!$this->variants->isAllowedWidth($width)) {
            throw new NotFoundHttpException(I18n::t('http.image_no_size'));
        }
        $original = $this->variants->resolveOriginal($path);
        if (null === $original) {
            throw new NotFoundHttpException(I18n::t('http.file_not_found'));
        }
        $ratio = null;
        $crop = $focal = null;
        if (null !== $a || null !== $b) {
            $ratio = ImageVariants::parseRatio($a . ':' . $b);
            if (null === $ratio || [$a, $b] !== $ratio) {
                throw new NotFoundHttpException(I18n::t('http.image_no_ratio')); // not reduced (32x18) or not a ratio: one address per picture
            }
            $media = $this->files->findOneBy(['path' => \rtrim($this->uploadUrl, '/') . '/' . $original[1]]);
            $key = ImageVariants::ratioKey($ratio);
            $crop = \is_array($media?->crops[$key] ?? null) ? $media->crops[$key] : null;
            if (null === $crop && !$this->variants->isAllowedRatio($key)) {
                throw new NotFoundHttpException(I18n::t('http.image_no_ratio'));
            }
            $focal = $media?->focal;
        }
        $file = $this->variants->ensure($original[0], $width, $path, $ratio, $crop, $focal);
        if (null === $file) {
            return new RedirectResponse(\rtrim($this->uploadUrl, '/') . '/' . $original[1], 302, ['Cache-Control' => 'public, max-age=3600']);
        }
        $response = new BinaryFileResponse($file, 200, ['Content-Type' => \str_ends_with($file, '.avif') ? 'image/avif' : 'image/webp']);
        $response->setPublic();
        if (null === $ratio) {
            $response->setMaxAge(31536000);
            $response->headers->addCacheControlDirective('immutable');
        } else {
            $response->setMaxAge(86400); // the frame of a file can be redrawn: a day, not a year
        }

        return $response;
    }
}
