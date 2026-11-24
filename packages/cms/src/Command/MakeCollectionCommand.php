<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Itk\Cms\Content\CollectionImporter;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Generator\CollectionClassBuilder;
use Itk\Cms\Generator\CollectionSpec;
use Itk\Cms\Generator\CollectionSpecReader;
use Itk\Cms\Generator\FieldSpec;
use Itk\Cms\Generator\GeneratedFile;
use Itk\Cms\Generator\MakeContext;
use Itk\Cms\Generator\Naming;
use Itk\Cms\Generator\TableReader;
use Itk\Cms\I18n\I18n;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * itk:make:collection [key] [название]                               — класс коллекции с парой полей
 * itk:make:collection --from=форма.json                              — класс по описанию {key, title, fields: [{key, label, type, required}]}
 * itk:make:collection --from=table:имя_таблицы [--import] [--map=…]  — класс по существующей SQL-таблице и (по желанию) перенос её строк в записи.
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
        // the importer (and with it the schema) is taken only after the new class is known to the registry
        #[AutowireLocator([CollectionImporter::class])]
        private readonly ContainerInterface $later,
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
            ->addOption('import', null, InputOption::VALUE_NONE, I18n::t('ops.make_collection_import_option'))
            ->addOption('publish', null, InputOption::VALUE_NONE, I18n::t('ops.make_collection_publish_option'))
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
        $import = (bool) $input->getOption('import');
        $dry = (bool) $input->getOption('dry-run');

        try {
            [$spec, $rows, $db] = $this->spec($input);
            if ($import && null === $db && [] === $rows) {
                $io->error(I18n::t('ops.make_collection_import_needs_source'));

                return Command::INVALID;
            }
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

        $plan = $this->make->writer->plan($this->make->layout->root(), [new GeneratedFile($path, $code, true)], (bool) $input->getOption('force'));
        $this->printPlan($io, $plan, $dry);
        $exists = 'conflict' === $plan[0]['status'];
        if ($dry) {
            $io->section($path);
            $io->writeln(\rtrim($code));
        } elseif ($exists && !$import) {
            $io->error(I18n::t('ops.make_file_conflict'));

            return Command::FAILURE;
        } elseif (!$exists) {
            $this->make->writer->write($this->make->layout->root(), $plan);
        }

        if ($import) {
            return $this->runImport($io, $input, $spec, $fqcn, $path, $code, $rows, $db, $dry, $exists && !$dry);
        }

        if ($dry) {
            $io->note(I18n::t('ops.dry_run_note'));

            return Command::SUCCESS;
        }
        $this->after($io, $fqcn);
        $io->success(I18n::t('ops.done'));

        return Command::SUCCESS;
    }

    /**
     * @return array{0: CollectionSpec, 1: list<array<string, mixed>>, 2: Connection|null} the spec, the rows of a JSON file, the connection of a table
     */
    private function spec(InputInterface $input): array
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
            $spec = new CollectionSpec('' !== $key ? $key : $stripped, Naming::humanize('' !== $key ? $key : $stripped), $spec->fields, null, \is_string($icon) ? $icon : null, \is_string($group) ? $group : null, $spec->source, $spec->notes);

            return [$spec, [], $db];
        }
        if ('' !== $from) {
            $file = \is_file($from) ? $from : $this->make->layout->root() . '/' . \ltrim($from, '/');
            [$spec, $rows] = $this->reader->fromFile($file);
            $spec = new CollectionSpec('' !== $key ? $key : $spec->key, $spec->title, $spec->fields, $spec->singular, \is_string($icon) ? $icon : $spec->icon, \is_string($group) ? $group : $spec->group, $spec->source, $spec->notes);

            return [$spec, $rows, null];
        }

        // no source: a collection with two fields to start from
        $spec = new CollectionSpec($key, '' !== $key ? Naming::humanize($key) : '', [
            new FieldSpec('name', Naming::label('name'), 'text', true, 60),
            new FieldSpec('photo', Naming::label('photo'), 'image'),
        ], null, \is_string($icon) ? $icon : null, \is_string($group) ? $group : null);

        return [$spec, [], null];
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

    /**
     * @param list<array<string, mixed>> $jsonRows
     */
    private function runImport(SymfonyStyle $io, InputInterface $input, CollectionSpec $spec, string $fqcn, string $path, string $code, array $jsonRows, ?Connection $db, bool $dry, bool $kept): int
    {
        if ($kept) {
            $io->writeln(I18n::t('ops.make_collection_class_kept'));
        }
        // the class must be known to the schema in THIS run: the container was built before the file existed
        $loaded = $this->load($fqcn, $path, $code);
        $this->make->addResource(new $fqcn());

        try {
            /** @var CollectionImporter $importer */
            $importer = $this->later->get(CollectionImporter::class);
            $source = (string) ($spec->source['kind'] ?? 'json') . ':' . (string) ($spec->source['name'] ?? '');
            $rows = null !== $db ? $this->tables->rows($db, (string) $spec->source['name'], $spec) : $this->jsonRows($jsonRows, $spec);
            $result = $importer->import($spec->key, $rows, $source, (bool) $input->getOption('publish'), $dry);
        } catch (ContentException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            if (null !== $loaded && \is_file($loaded)) {
                \unlink($loaded);
            }
        }

        $io->section(I18n::t($dry ? 'ops.make_import_section_dry' : 'ops.make_import_section'));
        // i18n-dynamic: ops.make_import_result (+ _dry, + _failed)
        $io->writeln(I18n::t(
            ($dry ? 'ops.make_import_result_dry' : 'ops.make_import_result') . ([] !== $result->failed ? '_failed' : ''),
            ['created' => $result->created, 'updated' => $result->updated, 'failed' => \count($result->failed)],
        ));
        if ([] !== $result->preview) {
            $keys = \array_keys($result->preview[0][1]);
            $io->table(\array_merge([I18n::t('ops.make_import_source_id')], $keys), \array_map(static fn (array $r): array => \array_merge([$r[0]], \array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : (string) \json_encode($v, \JSON_UNESCAPED_UNICODE), \array_values($r[1]))), $result->preview));
        }
        foreach ($result->failed as $f) {
            $io->warning($f);
        }
        if ([] !== $result->ignoredKeys) {
            $io->note(I18n::t('ops.make_import_ignored', ['keys' => \implode(', ', $result->ignoredKeys)]));
        }
        if ($dry) {
            $io->note(I18n::t('ops.dry_run_note'));
        } else {
            $io->success(I18n::t($input->getOption('publish') ? 'ops.done' : 'ops.make_import_done_drafts'));
        }

        return [] === $result->failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}>
     */
    private function jsonRows(array $rows, CollectionSpec $spec): \Generator
    {
        $keys = \array_map(static fn (FieldSpec $f): string => $f->key, $spec->fields);
        foreach ($rows as $i => $row) {
            yield [(string) ($row['id'] ?? $i + 1), \array_intersect_key($row, \array_flip($keys))];
        }
    }

    /**
     * Makes the class loadable: from disk, and for a dry run (nothing written) from a temporary file.
     *
     * @return string|null the temporary file to delete afterwards
     */
    private function load(string $fqcn, string $path, string $code): ?string
    {
        if (\class_exists($fqcn)) {
            return null;
        }
        $file = $this->make->layout->root() . '/' . $path;
        if (\is_file($file)) {
            require_once $file;

            return null;
        }
        $tmp = \tempnam(\sys_get_temp_dir(), 'itk_make_') ?: throw new \RuntimeException(I18n::t('ops.make_tmp_failed'));
        \file_put_contents($tmp, $code);
        require_once $tmp;

        return $tmp;
    }
}
