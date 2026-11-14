<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Rendering\PageCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Forgets every page kept by PageCache, for a deploy script or after the site changed data the fingerprint does not see. */
#[AsCommand(name: 'itk:cache:clear', description: 'ops.cmd_cache_clear_description')]
final class ClearPageCacheCommand extends Command
{
    public function __construct(private readonly PageCache $cache)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->cache->clear()) {
            $io->error(I18n::t('ops.cache_clear_failed'));

            return Command::FAILURE;
        }
        $io->success(I18n::t('ops.cache_cleared'));

        return Command::SUCCESS;
    }
}
