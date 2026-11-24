<?php

declare(strict_types=1);

namespace Itk\Cms\Admin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\RuntimeExtensionInterface;

/** Bundle files follow the site's Symfony Asset package, or the current installation directory when Asset is absent. */
final class BundleAssets implements RuntimeExtensionInterface
{
    private const PATH = 'bundles/itkcms/';

    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire(service: 'assets.packages')]
        private readonly ?object $packages = null,
    ) {
    }

    public function url(string $file): string
    {
        $path = self::PATH . \ltrim($file, '/');
        if (null !== $this->packages && \is_callable([$this->packages, 'getUrl'])) {
            return (string) $this->packages->getUrl($path);
        }

        return \rtrim($this->requests->getCurrentRequest()?->getBasePath() ?? '', '/') . '/' . $path;
    }
}
