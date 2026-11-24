<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Generator\GeneratedFile;
use Itk\Cms\Generator\Naming;
use Itk\Cms\I18n\I18n;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/** @internal */
#[AsCommand(name: 'itk:make:section', description: 'ops.cmd_make_section_description')]
final class MakeSectionCommand extends AbstractMakeCommand
{
    protected function kind(): string
    {
        return 'section';
    }

    protected function defaultDir(): string
    {
        return 'src/Itk/Section';
    }

    protected function suffix(): string
    {
        return 'Section';
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('group', null, InputOption::VALUE_REQUIRED, I18n::t('ops.make_section_group_option'), 'Тексты и картинки'); // i18n-ignore: the default group is data of the site
    }

    protected function files(string $key, string $title, string $namespace, string $class, InputInterface $input): array
    {
        return [
            new GeneratedFile($this->path((string) $input->getOption('dir'), $class . '.php'), $this->make->skeleton->render('section.php', [
                'namespace' => $namespace, 'class' => $class, 'key' => $key, 'titleQ' => Naming::php($title), 'groupQ' => Naming::php((string) $input->getOption('group')),
            ]), true),
            new GeneratedFile('templates/sections/' . $key . '.html.twig', $this->make->skeleton->render('section.html.twig', ['key' => $key, 'cssClass' => \str_replace('_', '-', $key)])),
        ];
    }
}
