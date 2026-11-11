<?php

namespace Tokenbox\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tokenbox\Project;
use Tokenbox\Ui;

final class ScanCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('scan')
            ->setDescription('List Composer projects and the private hosts each one needs credentials for')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Include projects with no private hosts')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspace = $this->workspace($input);
        $projects = $input->getOption('all')
            ? $workspace->projects
            : array_values(array_filter($workspace->projects, fn (Project $p) => $p->hosts));

        if ($input->getOption('json')) {
            $output->writeln(json_encode(array_map(fn (Project $p) => [
                'name' => $p->name,
                'dir' => $p->dir,
                'hosts' => array_map(fn ($host) => [
                    'host' => $host,
                    'covered_by' => array_map(fn ($c) => $c->source->scope.':'.$c->type, $workspace->credentialsFor($p, $host)),
                ], array_keys($p->hosts)),
            ], $projects), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        Ui::banner($output, 'projects in '.implode(', ', array_map(fn ($r) => str_replace((string) getenv('HOME'), '~', $r), $workspace->roots)));

        $gaps = array_map(fn ($g) => $g['project']->dir.'#'.$g['host'], $workspace->gaps(online: true));

        $rows = [];
        foreach ($projects as $p) {
            $hosts = array_map(fn ($host) => match (true) {
                (bool) $workspace->credentialsFor($p, $host) => "<fg=green>✓</> $host",
                in_array($p->dir.'#'.$host, $gaps, true) => "<fg=red>✕</> $host",
                default => "<fg=gray>○ $host (public)</>",
            }, array_keys($p->hosts));

            $rows[] = [
                "<options=bold>{$p->name}</>",
                '<fg=gray>'.Ui::short($p->displayDir(), 40).'</>',
                $hosts ? implode("\n", $hosts) : '<fg=gray>public only</>',
                $p->authSource()->exists() ? '<fg=blue>auth.json</>' : '',
            ];
        }

        if ($rows) {
            Ui::table($output, ['Project', 'Path', 'Private hosts', ''], $rows);
        }
        $hidden = count($workspace->projects) - count($projects);
        $output->writeln('');
        $output->writeln('  '.count($workspace->projects).' projects found'
            .($hidden ? " <fg=gray>· $hidden public-only hidden (use --all)</>" : ''));
        $output->writeln('');

        return self::SUCCESS;
    }
}
