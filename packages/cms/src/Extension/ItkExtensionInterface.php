<?php

declare(strict_types=1);

namespace Itk\Cms\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A package (or the site) that adds to ITK CMS in one call: resources, Twig paths, menu items, admin assets, notification channels, health checks.
 * Every service that implements this interface is picked up by itself (tag `itk.extension`).
 */
#[AutoconfigureTag('itk.extension')]
interface ItkExtensionInterface
{
    public function register(Registry $registry): void;
}
