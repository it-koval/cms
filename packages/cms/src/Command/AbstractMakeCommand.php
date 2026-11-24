<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Generator\FileWriter;
use Itk\Cms\Generator\GeneratedFile;
use Itk\Cms\Generator\MakeContext;
use Itk\Cms\Generator\Naming;
use Itk\Cms\I18n\I18n;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generators of the site code: a PHP class of the resource in src/Itk/… (and a Twig / JS stub where it is needed) from packages/cms/skeleton/.
 * Questions are asked when an argument is missing (and the call is interactive); every question has a flag. A class that exists is never overwritten without --force.
 *
 * @internal
 */
abstract class AbstractMakeCommand extends Command
{
    public function __construct(protected readonly MakeContext $make)
    {
        parent::__construct();
    }

    /** 'collection' | 'template' | 'section' | 'settings' | 'field' | 'plugin' */
    abstract protected function kind(): string;

    /** Folder of the class, relative to the project. */
    abstract protected function defaultDir(): string;

    /** Ending of the class name: ToursCollection. */
    abstract protected function suffix(): string;

    /** What the key is called in questions and messages. */
    protected function keyLabel(): string
    {
        return I18n::t('ops.make_key_label');
    }

    protected function nameLabel(): string
    {
        return I18n::t('ops.make_name_label');
    }

    /**
     * The files to write: the class first (main), then the Twig / JS stubs.
     *
     * @return list<GeneratedFile>
     */
    abstract protected function files(string $key, string $title, string $namespace, string $class, InputInterface $input): array;

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::OPTIONAL, $this->keyLabel())
            ->addArgument('name', InputArgument::OPTIONAL, $this->nameLabel())
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, I18n::t('ops.make_dir_option'), $this->defaultDir())
            ->addOption('force', 'f', InputOption::VALUE_NONE, I18n::t('ops.make_force_option'))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, I18n::t('ops.make_dry_run_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = $this->ask($io, $input, 'key', $this->keyLabel(), null, static fn (?string $v): string => Naming::isKey((string) $v) ? (string) $v : throw new \RuntimeException(I18n::t('ops.make_key_invalid')));
        if (null === $key) {
            $io->error(I18n::t('ops.make_key_required', ['kind' => $this->kind()]));

            return Command::INVALID;
        }
        if (!Naming::isKey($key)) {
            $io->error(I18n::t('ops.make_key_invalid'));

            return Command::INVALID;
        }
        $title = $this->ask($io, $input, 'name', $this->nameLabel(), Naming::humanize($key)) ?? Naming::humanize($key);

        try {
            $namespace = $this->make->layout->namespaceOf((string) $input->getOption('dir'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $class = Naming::studly($key) . $this->suffix();

        if (null !== ($owner = $this->make->keyOwner($this->kind(), $key, $namespace . '\\' . $class))) {
            $io->error(I18n::t('ops.make_key_taken', ['key' => $key, 'owner' => $owner]));

            return Command::FAILURE;
        }

        return $this->apply($io, $input, $this->files($key, $title, $namespace, $class, $input), $namespace . '\\' . $class);
    }

    /** @param list<GeneratedFile> $files */
    protected function apply(SymfonyStyle $io, InputInterface $input, array $files, string $fqcn): int
    {
        $plan = $this->make->writer->plan($this->make->layout->root(), $files, (bool) $input->getOption('force'));
        $dry = (bool) $input->getOption('dry-run');
        $this->printPlan($io, $plan, $dry);
        if ($dry) {
            $io->section($this->make->layout->relative($files[0]->path));
            $io->writeln(\rtrim($files[0]->content));
            $io->note(I18n::t('ops.dry_run_note'));

            return Command::SUCCESS;
        }
        if ([] !== \array_filter($plan, static fn (array $p): bool => FileWriter::CONFLICT === $p['status'])) {
            $io->error(I18n::t('ops.make_file_conflict'));

            return Command::FAILURE;
        }
        $this->make->writer->write($this->make->layout->root(), $plan);
        $this->after($io, $fqcn);
        $io->success(I18n::t('ops.done'));

        return Command::SUCCESS;
    }

    protected function after(SymfonyStyle $io, string $fqcn): void
    {
        $io->writeln(I18n::t('ops.make_after'));
    }

    /** @param list<array{file: GeneratedFile, status: string}> $plan */
    protected function printPlan(SymfonyStyle $io, array $plan, bool $dry): void
    {
        $words = [
            FileWriter::CREATE => I18n::t($dry ? 'ops.make_file_will_create' : 'ops.make_file_created'),
            FileWriter::REPLACE => I18n::t($dry ? 'ops.make_file_will_replace' : 'ops.make_file_replaced'),
            FileWriter::KEEP => I18n::t('ops.make_file_kept'),
            FileWriter::CONFLICT => I18n::t('ops.make_file_exists'),
        ];
        foreach ($plan as ['file' => $f, 'status' => $status]) {
            $io->writeln(\sprintf(' %s %s (%s)', FileWriter::CONFLICT === $status ? '!' : '+', $f->path, $words[$status]));
        }
    }

    /** The value of an argument, else (interactive) the answer to a question, else the default. */
    protected function ask(SymfonyStyle $io, InputInterface $input, string $argument, string $question, ?string $default, ?callable $validator = null): ?string
    {
        $value = $input->hasArgument($argument) ? $input->getArgument($argument) : null;
        if (\is_string($value) && '' !== $value) {
            return $value;
        }
        if ($input->isInteractive()) {
            $answer = $io->ask($question, $default, $validator);

            return null === $answer || '' === $answer ? $default : (string) $answer;
        }

        return $default;
    }

    /** Helper for the Twig / JS stubs: the path below the project root. */
    protected function path(string ...$parts): string
    {
        return \implode('/', $parts);
    }
}
