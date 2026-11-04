<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Mcp\McpStdio;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The MCP server on standard input and output, for assistants that start a program (Claude Desktop, Claude Code with a command):
 *   ITK_MCP_TOKEN=itk_… bin/console itk:mcp        (or --token=itk_…, which other users of the machine can see in the process list)
 * Nothing but JSON-RPC lines goes to the output. In Docker: `docker compose exec -T php bin/console itk:mcp` (-T: no terminal).
 */
#[AsCommand(name: 'itk:mcp', description: 'ops.cmd_mcp_description')]
final class McpCommand extends Command
{
    public function __construct(private readonly McpStdio $stdio)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('token', null, InputOption::VALUE_REQUIRED, I18n::t('ops.mcp_token_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // a warning on the output would break the protocol
        @\ini_set('display_errors', 'stderr');
        $plain = (string) ($input->getOption('token') ?: ($_SERVER['ITK_MCP_TOKEN'] ?? $_ENV['ITK_MCP_TOKEN'] ?? \getenv('ITK_MCP_TOKEN') ?: ''));
        if ('' === $plain) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln(I18n::t('ops.mcp_no_key'));

            return Command::FAILURE;
        }

        return $this->stdio->serve($plain, \STDIN, \STDOUT, \STDERR);
    }
}
