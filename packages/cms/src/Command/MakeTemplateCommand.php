<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Generator\GeneratedFile;
use Itk\Cms\Generator\Naming;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(name: 'itk:make:template', description: 'ops.cmd_make_template_description')]
final class MakeTemplateCommand extends AbstractMakeCommand
{
    protected function kind(): string
    {
        return 'template';
    }

    protected function defaultDir(): string
    {
        return 'src/Itk/Template';
    }

    protected function suffix(): string
    {
        return 'Template';
    }

    protected function files(string $key, string $title, string $namespace, string $class, InputInterface $input): array
    {
        return [
            new GeneratedFile($this->path((string) $input->getOption('dir'), $class . '.php'), $this->make->skeleton->render('template.php', [
                'namespace' => $namespace, 'class' => $class, 'key' => $key, 'titleQ' => Naming::php($title),
            ]), true),
            new GeneratedFile('templates/pages/' . $key . '.html.twig', $this->make->skeleton->render('template.html.twig', ['key' => $key])),
        ];
    }
}
