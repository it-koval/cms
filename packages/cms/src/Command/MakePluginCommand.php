<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Generator\GeneratedFile;
use Itk\Cms\Generator\Naming;
use Itk\Cms\I18n\I18n;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @internal */
#[AsCommand(name: 'itk:make:plugin', description: 'ops.cmd_make_plugin_description')]
final class MakePluginCommand extends AbstractMakeCommand
{
    protected function kind(): string
    {
        return 'plugin';
    }

    protected function defaultDir(): string
    {
        return 'src/Itk/Plugin';
    }

    protected function suffix(): string
    {
        return 'Extension';
    }

    protected function keyLabel(): string
    {
        return I18n::t('ops.make_plugin_key_label');
    }

    protected function nameLabel(): string
    {
        return I18n::t('ops.make_plugin_name_label');
    }

    protected function files(string $key, string $title, string $namespace, string $class, InputInterface $input): array
    {
        $js = 'public/itk/plugins/' . $key . '/admin.js';
        $url = '/itk/plugins/' . $key . '/admin.js';

        return [
            new GeneratedFile($this->path((string) $input->getOption('dir'), $class . '.php'), $this->make->skeleton->render('extension.php', [
                'namespace' => $namespace, 'class' => $class, 'key' => $key, 'title' => $title, 'titleQ' => Naming::php($title), 'jsPath' => $js, 'jsUrl' => $url, 'namespaceTwig' => Naming::studly($key),
            ]), true),
            new GeneratedFile($js, $this->make->skeleton->render('plugin.js', [
                'key' => $key, 'title' => $title, 'titleJs' => Naming::js($title), 'jsUrl' => $url, 'component' => Naming::studly($key),
            ])),
        ];
    }

    protected function after(SymfonyStyle $io, string $fqcn): void
    {
        $io->writeln(I18n::t('ops.make_plugin_after'));
    }
}
