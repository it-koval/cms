<?php

declare(strict_types=1);

namespace @@namespace@@;

use Itk\Cms\Extension\ItkExtensionInterface;
use Itk\Cms\Extension\NavItem;
use Itk\Cms\Extension\Registry;

/**
 * Plugin “@@title@@”: its own screen in the admin panel (a menu item + @@jsPath@@).
 * Registered automatically via the itk.extension tag. Everything else you can plug in with one call is in the bundle README, section “Расширения” (Extensions).
 */
final class @@class@@ implements ItkExtensionInterface
{
    public function register(Registry $registry): void
    {
        $registry
            // ->resources(ToursCollection::class)
            // ->twigPaths(__DIR__ . '/../../../templates/@@key@@', '@@namespaceTwig@@')   // {% include '@@@namespaceTwig@@/block.html.twig' %}
            ->menu(NavItem::make(@@titleQ@@, '/x/@@key@@')->icon('list'))
            ->adminAssets(js: ['@@jsUrl@@']);
    }
}
