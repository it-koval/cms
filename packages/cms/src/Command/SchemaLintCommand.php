<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\SchemaLint;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks the schema of the site (config/itk/schema.php and the resource classes) and the Twig templates of its sections and pages. Exit code 1 when there is an error.
 *
 * @internal
 */
#[AsCommand(name: 'itk:schema:lint', description: 'ops.cmd_schema_lint_description')]
final class SchemaLintCommand extends Command
{
    public function __construct(private readonly SchemaLint $lint)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->lint->run();
        if ([] !== $result['warnings']) {
            $io->warning($result['warnings']);
        }
        if ([] !== $result['errors']) {
            $io->error($result['errors']);

            return Command::FAILURE;
        }
        $io->success(I18n::t('ops.schema_lint_ok'));

        return Command::SUCCESS;
    }
}
