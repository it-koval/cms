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
#[AsCommand(name: 'itk:make:field', description: 'ops.cmd_make_field_description')]
final class MakeFieldCommand extends AbstractMakeCommand
{
    protected function kind(): string
    {
        return 'field';
    }

    protected function defaultDir(): string
    {
        return 'src/Itk/Field';
    }

    protected function suffix(): string
    {
        return 'Field';
    }

    protected function keyLabel(): string
    {
        return I18n::t('ops.make_field_key_label');
    }

    protected function nameLabel(): string
    {
        return I18n::t('ops.make_field_name_label');
    }

    protected function files(string $key, string $title, string $namespace, string $class, InputInterface $input): array
    {
        $js = 'public/itk/fields/' . $key . '.js';

        return [
            new GeneratedFile($this->path((string) $input->getOption('dir'), $class . '.php'), $this->make->skeleton->render('field.php', [
                'namespace' => $namespace, 'class' => $class, 'key' => $key, 'title' => $title, 'type' => $key, 'jsPath' => $js, 'jsUrl' => '/itk/fields/' . $key . '.js',
            ]), true),
            new GeneratedFile($js, $this->make->skeleton->render('field.js', [
                'title' => $title, 'type' => $key, 'component' => Naming::studly($key) . 'Field', 'jsUrl' => '/itk/fields/' . $key . '.js',
            ])),
        ];
    }

    protected function after(SymfonyStyle $io, string $fqcn): void
    {
        $io->writeln(I18n::t('ops.make_field_next'));
        $io->writeln(I18n::t('ops.make_field_usage', ['class' => \substr($fqcn, (int) \strrpos($fqcn, '\\') + 1)]));
    }
}
