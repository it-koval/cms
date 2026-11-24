<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Generator\CollectionClassBuilder;
use Itk\Cms\Generator\CollectionSpec;
use Itk\Cms\Generator\CollectionSpecReader;
use Itk\Cms\Generator\FieldSpec;
use Itk\Cms\Generator\GeneratedFile;
use Itk\Cms\Generator\MakeContext;
use Itk\Cms\Generator\Naming;
use Itk\Cms\Generator\TableReader;
use Itk\Cms\I18n\I18n;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates a collection class from a JSON description, an existing SQL table, or two starter fields.
 *
 * @internal
 */
#[AsCommand(name: 'itk:make:collection', description: 'ops.cmd_make_collection_description')]
final class MakeCollectionCommand extends AbstractMakeCommand
{
    public function __construct(
        MakeContext $make,
        private readonly CollectionClassBuilder $builder,
        private readonly CollectionSpecReader $reader,
        private readonly TableReader $tables,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct($make);
    }

    protected function kind(): string
    {
        return 'collection';
    }

    protected function defaultDir(): string
    {
        return 'src/Itk/Collection';
    }

    protected function suffix(): string
    {
        return 'Collection';
    }

    protected function files(string $key, string $title, string $namespace, string $class, InputInterface $input): array
    {
        throw new \LogicException('Collections are generated in execute().');
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, I18n::t('ops.make_collection_from_option'))
            ->addOption('map', null, InputOption::VALUE_REQUIRED, I18n::t('ops.make_collection_map_option'))
            ->addOption('id', null, InputOption::VALUE_REQUIRED, I18n::t('ops.make_collection_id_option'))
            ->addOption('strip-prefix', null, InputOption::VALUE_REQUIRED, I18n::t('ops.make_collection_strip_prefix_option'), '')
            ->addOption('connection', null, InputOption::VALUE_REQUIRED, I18n::t('ops.make_collection_connection_option'), 'default')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, I18n::t('ops.make_collection_group_option'))
            ->addOption('icon', null, InputOption::VALUE_REQUIRED, I18n::t('ops.make_collection_icon_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $spec = $this->spec($input);
            $key = $this->ask($io, $input, 'key', I18n::t('ops.make_collection_key_label'), '' !== $spec->key ? $spec->key : null, static fn (?string $v): string => Naming::isKey((string) $v) ? (string) $v : throw new \RuntimeException(I18n::t('ops.make_key_invalid')));
            if (null === $key || !Naming::isKey($key)) {
                $io->error(I18n::t('ops.make_collection_key_required'));

                return Command::INVALID;
            }
            $title = $this->ask($io, $input, 'name', I18n::t('ops.make_name_label'), '' !== $spec->title && $spec->key === $key ? $spec->title : Naming::humanize($key)) ?? Naming::humanize($key);
            $spec = $spec->withKeyAndTitle($key, $title);
            $namespace = $this->make->layout->namespaceOf((string) $input->getOption('dir'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $class = Naming::studly($key) . $this->suffix();
        $fqcn = $namespace . '\\' . $class;

        if (null !== ($owner = $this->make->keyOwner('collection', $key, $fqcn))) {
            $io->error(I18n::t('ops.make_key_taken', ['key' => $key, 'owner' => $owner]));

            return Command::FAILURE;
        }

        foreach ($spec->notes as $note) {
            $io->writeln(' ~ ' . $note);
        }
        $path = $this->path((string) $input->getOption('dir'), $class . '.php');
        $code = $this->builder->build($spec, $namespace, $class);

        return $this->apply($io, $input, [new GeneratedFile($path, $code, true)], $fqcn);
    }

    private function spec(InputInterface $input): CollectionSpec
    {
        $from = (string) $input->getOption('from');
        $group = $input->getOption('group');
        $icon = $input->getOption('icon');
        $key = (string) $input->getArgument('key');

        if (\str_starts_with($from, 'table:')) {
            $table = \substr($from, 6);
            $map = $this->map((string) $input->getOption('map'));
            $db = $this->doctrine->getConnection((string) $input->getOption('connection'));
            if (!$db instanceof Connection) {
                throw new \InvalidArgumentException(I18n::t('ops.make_collection_connection_invalid', ['name' => (string) $input->getOption('connection')]));
            }
            $spec = $this->tables->inspect($db, $table, $map, $input->getOption('id') ? (string) $input->getOption('id') : null);
            $stripped = Naming::keyOf(\array_slice(\explode('.', $table), -1)[0], (string) $input->getOption('strip-prefix'));

            return new CollectionSpec('' !== $key ? $key : $stripped, Naming::humanize('' !== $key ? $key : $stripped), $spec->fields, null, \is_string($icon) ? $icon : null, \is_string($group) ? $group : null, $spec->source, $spec->notes);
        }
        if ('' !== $from) {
            $file = \is_file($from) ? $from : $this->make->layout->root() . '/' . \ltrim($from, '/');
            $spec = $this->reader->fromFile($file);

            return new CollectionSpec('' !== $key ? $key : $spec->key, $spec->title, $spec->fields, $spec->singular, \is_string($icon) ? $icon : $spec->icon, \is_string($group) ? $group : $spec->group, $spec->source, $spec->notes);
        }

        // no source: a collection with two fields to start from
        return new CollectionSpec($key, '' !== $key ? Naming::humanize($key) : '', [
            new FieldSpec('name', Naming::label('name'), 'text', true, 60),
            new FieldSpec('photo', Naming::label('photo'), 'image'),
        ], null, \is_string($icon) ? $icon : null, \is_string($group) ? $group : null);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> column → [field key|null, type|null] */
    private function map(string $map): array
    {
        $out = [];
        foreach (\array_filter(\array_map('trim', \explode(',', $map))) as $pair) {
            $parts = \explode(':', $pair);
            if (\count($parts) < 2 || \count($parts) > 3 || '' === $parts[0] || '' === $parts[1]) {
                throw new \InvalidArgumentException(I18n::t('ops.make_collection_map_invalid', ['pair' => $pair]));
            }
            $out[$parts[0]] = ['-' === $parts[1] ? null : $parts[1], $parts[2] ?? null];
        }

        return $out;
    }
}
