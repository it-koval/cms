<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Repository\MediaFileRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'itk:media:warm', description: 'ops.cmd_warm_media_description')]
final class WarmMediaCommand extends Command
{
    public function __construct(
        private readonly MediaFileRepository $files,
        private readonly ImageVariants $variants,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('width', 'w', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, I18n::t('ops.warm_media_width_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = \array_map('intval', (array) $input->getOption('width'));
        foreach ($only as $w) {
            if (!$this->variants->isAllowedWidth($w)) {
                $io->error(I18n::t('ops.warm_media_width_invalid', ['width' => $w, 'allowed' => \implode(', ', $this->variants->widths())]));

                return Command::INVALID;
            }
        }

        $made = $existing = $skipped = 0;
        $bar = $io->createProgressBar();
        $bar->start();
        foreach ($this->files->imagesForWarming() as $image) {
            $result = $this->variants->warm($image['path'], $image['width'], $only);
            $made += $result['made'];
            $existing += $result['existing'];
            $skipped += $result['skipped'];
            $bar->advance();
        }
        $bar->finish();
        $io->newLine(2);
        $io->success(I18n::t('ops.warm_media_done', ['made' => $made, 'existing' => $existing, 'skipped' => $skipped]));

        return Command::SUCCESS;
    }
}
