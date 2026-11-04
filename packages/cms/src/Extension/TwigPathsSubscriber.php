<?php

declare(strict_types=1);

namespace Itk\Cms\Extension;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Loader\FilesystemLoader;

/**
 * Adds the template folders extensions registered (Registry::twigPaths) to Twig, before the first template is loaded.
 *
 * @internal
 */
final class TwigPathsSubscriber implements EventSubscriberInterface
{
    private bool $done = false;

    public function __construct(
        private readonly Registry $extensions,
        #[Autowire(service: 'twig.loader.native_filesystem')]
        private readonly FilesystemLoader $loader,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['add', 256],
            ConsoleEvents::COMMAND => ['add', 256],
        ];
    }

    public function add(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        foreach ($this->extensions->twigPathList() as ['dir' => $dir, 'namespace' => $namespace]) {
            null === $namespace ? $this->loader->addPath($dir) : $this->loader->addPath($dir, $namespace);
        }
    }
}
