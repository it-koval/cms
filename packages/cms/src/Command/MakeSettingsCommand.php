<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Generator\GeneratedFile;
use Itk\Cms\Generator\Naming;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

/** @internal */
#[AsCommand(name: 'itk:make:settings', description: 'ops.cmd_make_settings_description')]
final class MakeSettingsCommand extends AbstractMakeCommand
{
    protected function kind(): string
    {
        return 'settings';
    }

    protected function defaultDir(): string
    {
        return 'src/Itk/Settings';
    }

    protected function suffix(): string
    {
        return 'Settings';
    }

    protected function files(string $key, string $title, string $namespace, string $class, InputInterface $input): array
    {
        return [
            new GeneratedFile($this->path((string) $input->getOption('dir'), $class . '.php'), $this->make->skeleton->render('settings.php', [
                'namespace' => $namespace, 'class' => $class, 'key' => $key, 'titleQ' => Naming::php($title), 'example' => Naming::camel($key) . 'Text',
            ]), true),
        ];
    }
}
