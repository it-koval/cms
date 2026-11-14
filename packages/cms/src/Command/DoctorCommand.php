<?php

declare(strict_types=1);

namespace Itk\Cms\Command;

use Itk\Cms\Health\Doctor;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\I18n\I18n;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Checks the site in one pass (see Doctor). Without --ci it only tells; with --ci the exit code is 1 when a check failed (for a deploy script or CI). */
#[AsCommand(name: 'itk:doctor', description: 'ops.cmd_doctor_description')]
final class DoctorCommand extends Command
{
    public function __construct(private readonly Doctor $doctor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('ci', null, InputOption::VALUE_NONE, I18n::t('ops.doctor_ci_option'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $group = null;
        $marks = [HealthResult::OK => '<info>ok</info>   ', HealthResult::OFF => '<fg=gray>' . I18n::t('ops.doctor_mark_off') . '</>', HealthResult::WARN => '<comment>' . I18n::t('ops.doctor_mark_warn') . '</comment>', HealthResult::FAIL => '<error> ' . I18n::t('ops.doctor_mark_fail') . ' </error>'];
        $count = [HealthResult::OK => 0, HealthResult::OFF => 0, HealthResult::WARN => 0, HealthResult::FAIL => 0];
        foreach ($this->doctor->run() as $line) {
            if ($group !== $line['group']) {
                $group = $line['group'];
                $io->section($group);
            }
            $status = \array_key_exists($line['status'], $marks) ? $line['status'] : HealthResult::OK; // an unknown status must not stop the report
            ++$count[$status];
            $io->writeln(\sprintf(' %s %s: %s', $marks[$status], $line['name'], $line['message']));
        }
        $io->newLine();
        $io->writeln(I18n::t('ops.doctor_total', ['errors' => $count[HealthResult::FAIL], 'warnings' => $count[HealthResult::WARN], 'ok' => $count[HealthResult::OK], 'off' => $count[HealthResult::OFF]]));

        return $input->getOption('ci') && $count[HealthResult::FAIL] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
