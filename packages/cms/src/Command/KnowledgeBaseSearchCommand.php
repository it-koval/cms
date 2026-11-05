<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\I18n\I18n;
use Itk\Cms\KnowledgeBase\KnowledgeSearch;
use Itk\Cms\KnowledgeBase\Model\Hit;
use Itk\Cms\KnowledgeBase\Model\KnowledgeBaseUnavailable;
use Itk\Cms\KnowledgeBase\Model\SearchOptions;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * itk:knowledge-base:search "<query>" [--locale=ru] [--limit=8] [--template=x]… [--collection=y]…
 * Prints the hits of KnowledgeSearch::search() as a table: score, title, address (or collection and id), section, the first 120 characters.
 * For the developer: does the index answer what the site is about, before a site search or a chatbot is built on it.
 */
#[AsCommand(name: 'itk:knowledge-base:search', description: 'ops.cmd_knowledge_base_search_description')]
final class KnowledgeBaseSearchCommand extends Command
{
    public function __construct(private readonly KnowledgeSearch $knowledge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('query', InputArgument::REQUIRED, I18n::t('ops.knowledge_base_query_argument'));
        $this->addOption('locale', null, InputOption::VALUE_REQUIRED, I18n::t('ops.knowledge_base_locale_option'));
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, I18n::t('ops.knowledge_base_limit_option'), '8');
        $this->addOption('template', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, I18n::t('ops.knowledge_base_template_option'));
        $this->addOption('collection', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, I18n::t('ops.knowledge_base_collection_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $templates = \array_map('strval', (array) $input->getOption('template'));
        $collections = \array_map('strval', (array) $input->getOption('collection'));
        try {
            $options = new SearchOptions(
                locale: \is_string($input->getOption('locale')) ? $input->getOption('locale') : null,
                limit: (int) $input->getOption('limit'),
                templates: [] === $templates ? null : $templates,
                collections: [] === $collections ? null : $collections,
            );
            $result = $this->knowledge->search((string) $input->getArgument('query'), $options);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (KnowledgeBaseUnavailable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ([] === $result->hits) {
            $io->warning(I18n::t('ops.knowledge_base_no_hits'));

            return Command::SUCCESS;
        }
        $io->table(['#', 'title', 'where', 'section', 'text'], \array_map(static fn (Hit $h): array => [
            \number_format($h->score, 3),
            $h->source->title,
            SourceKind::Page === $h->source->kind ? (string) $h->source->url : $h->source->resourceKey . ' ' . $h->source->id,
            ($h->sectionType ?? '-') . ($h->heading ? ': ' . $h->heading : ''),
            \mb_strlen($h->text) > 120 ? \mb_substr($h->text, 0, 119) . '…' : $h->text,
        ], $result->hits));

        return Command::SUCCESS;
    }
}
