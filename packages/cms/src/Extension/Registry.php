<?php

declare(strict_types=1);

namespace Itk\Cms\Extension;

use Itk\Cms\Notification\NotificationChannelInterface;
use Itk\Cms\Resource\ResourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * What the extensions registered. ItkExtensionInterface::register() receives this object and calls it:
 *
 *   $r->resources(ToursCollection::class, TourTemplate::class)->twigPaths(__DIR__ . '/../templates', 'Tours')
 *     ->menu(NavItem::make('Отчёты', '/x/reports'))->adminAssets(js: ['/bundles/tours/admin.js'], css: ['/bundles/tours/admin.css']);
 *
 * Classes named here are used as services when the container has them (autoconfigured), else created with `new` (no constructor arguments).
 */
final class Registry
{
    /** @var list<class-string<ResourceInterface>> */
    private array $resources = [];
    /** @var list<array{dir: string, namespace: ?string}> */
    private array $twigPaths = [];
    /** @var list<NavItem> */
    private array $menu = [];
    /** @var list<string> */
    private array $js = [];
    /** @var list<string> */
    private array $css = [];
    /** @var list<string> */
    private array $channels = [];
    /** @var list<string> */
    private array $checks = [];
    /** @var list<string> */
    private array $mcpTools = [];
    private bool $booted = false;

    /** @param iterable<ItkExtensionInterface> $extensions */
    public function __construct(
        #[AutowireIterator('itk.extension')]
        private readonly iterable $extensions = [],
    ) {
    }

    /** @param class-string<ResourceInterface> ...$classes collections, templates, sections and settings, any mix */
    public function resources(string ...$classes): self
    {
        $this->checkClasses($classes, ResourceInterface::class);
        \array_push($this->resources, ...$classes);

        return $this;
    }

    /** @param class-string<ResourceInterface> ...$classes */
    public function sections(string ...$classes): self
    {
        return $this->resources(...$classes);
    }

    /** @param class-string<ResourceInterface> ...$classes */
    public function templates(string ...$classes): self
    {
        return $this->resources(...$classes);
    }

    /** @param class-string<ResourceInterface> ...$classes */
    public function collections(string ...$classes): self
    {
        return $this->resources(...$classes);
    }

    /** @param class-string<ResourceInterface> ...$classes */
    public function settings(string ...$classes): self
    {
        return $this->resources(...$classes);
    }

    /** Twig templates of the package: {% include '@Namespace/block.html.twig' %} (no namespace = the main templates folder). */
    public function twigPaths(string $dir, ?string $namespace = null): self
    {
        $this->twigPaths[] = ['dir' => $dir, 'namespace' => $namespace];

        return $this;
    }

    public function menu(NavItem ...$items): self
    {
        \array_push($this->menu, ...$items);

        return $this;
    }

    /**
     * Scripts and styles loaded by the admin after its own bundle (addresses on the site, '/bundles/…'). A script calls
     * window.ITK.registerPlugin({ fields: { type: Component }, panels: {…}, actions: {…} }).
     *
     * @param list<string> $js
     * @param list<string> $css
     */
    public function adminAssets(array $js = [], array $css = []): self
    {
        \array_push($this->js, ...\array_values($js));
        \array_push($this->css, ...\array_values($css));

        return $this;
    }

    /**
     * Channels that receive notifications (Itk\Cms\Notification\NotificationChannelInterface). A class that is a service of the container is a channel by itself
     * (autoconfigure tags it `itk.notification_channel`); naming it here also covers a class that is not a service (created with `new`, no constructor arguments).
     *
     * @param class-string<NotificationChannelInterface> ...$classes
     */
    public function notificationChannels(string ...$classes): self
    {
        $this->checkClasses($classes, NotificationChannelInterface::class);
        \array_push($this->channels, ...$classes);

        return $this;
    }

    /** @param class-string<\Itk\Cms\Health\HealthCheckInterface> ...$classes health checks of «Состояние сайта» (a class that implements the interface in a scanned folder is picked up without this; a class named here is created with `new`) */
    public function healthChecks(string ...$classes): self
    {
        \array_push($this->checks, ...$classes);

        return $this;
    }

    /**
     * Tools of the MCP server (AI assistants): classes that implement Itk\Cms\Mcp\McpToolInterface. A class that is a service of the container is a tool by itself
     * (autoconfigure tags it `itk.mcp_tool`); naming it here also covers a class that is not a service (created with `new`, no constructor arguments).
     *
     * @param class-string<\Itk\Cms\Mcp\McpToolInterface> ...$classes
     */
    public function mcpTools(string ...$classes): self
    {
        $this->checkClasses($classes, \Itk\Cms\Mcp\McpToolInterface::class);
        \array_push($this->mcpTools, ...$classes);

        return $this;
    }

    /** @return list<class-string<ResourceInterface>> */
    public function resourceClasses(): array
    {
        $this->boot();

        return \array_values(\array_unique($this->resources));
    }

    /** @return list<array{dir: string, namespace: ?string}> */
    public function twigPathList(): array
    {
        $this->boot();

        return $this->twigPaths;
    }

    /** @return list<NavItem> */
    public function menuItems(): array
    {
        $this->boot();

        return $this->menu;
    }

    /** @return array{js: list<string>, css: list<string>} */
    public function adminAssetList(): array
    {
        $this->boot();

        return ['js' => \array_values(\array_unique($this->js)), 'css' => \array_values(\array_unique($this->css))];
    }

    /** @return list<string> */
    public function channelClasses(): array
    {
        $this->boot();

        return \array_values(\array_unique($this->channels));
    }

    /** @return list<string> */
    public function mcpToolClasses(): array
    {
        $this->boot();

        return \array_values(\array_unique($this->mcpTools));
    }

    /** @return list<string> */
    public function healthCheckClasses(): array
    {
        $this->boot();

        return \array_values(\array_unique($this->checks));
    }

    /** Every extension registers once per process, on the first question. */
    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        foreach ($this->extensions as $extension) {
            $extension->register($this);
        }
    }

    /** @param list<string> $classes */
    private function checkClasses(array $classes, string $parent): void
    {
        foreach ($classes as $class) {
            if (!\class_exists($class) || !\is_subclass_of($class, $parent)) {
                throw new \InvalidArgumentException(\sprintf('"%s" must be a class that implements %s.', $class, $parent));
            }
        }
    }
}
