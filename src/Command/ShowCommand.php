<?php

namespace Tokenbox\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tokenbox\AuthSource;
use Tokenbox\Checker;
use Tokenbox\Credential;
use Tokenbox\Settings;
use Tokenbox\Ui;

use function Laravel\Prompts\spin;

final class ShowCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('show')
            ->setAliases(['why'])
            ->setDescription('Everything about one credential: health, where it’s stored, and every project that uses it and why')
            ->addArgument('host', InputArgument::OPTIONAL, 'Host of the credential')
            ->addOption('offline', 'o', InputOption::VALUE_NONE, 'Don’t contact the provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspace = $this->workspace($input);
        $c = RefreshCommand::pick($workspace, $input->getArgument('host'), 'Which credential?');
        $settings = Settings::load();

        Ui::banner($output, $c->host);

        $health = $input->getOption('offline') ? null : spin(
            fn () => (new Checker((int) $settings->get('warn_days')))->check($c, $workspace->probeUrls($c->host)),
            "Checking {$c->host}…",
        );

        $rows = [
            ['Status', $health ? Ui::badge($health).($health->detail ? "  <fg=gray>{$health->detail}</>" : '') : '<fg=gray>not checked</>'],
            ['Type', "{$c->type} <fg=gray>({$c->provider()->label})</>"],
            ['Account', $health?->account ?? '<fg=gray>—</>'],
            ['Token', $c->masked()],
            ['Scopes', $health?->scopes ? implode(', ', $health->scopes) : '<fg=gray>—</>'],
            ['Expires', $health ? Ui::expiry($health, (bool) $settings->get('warn_no_expiry')) : '<fg=gray>—</>'],
            ['Stored in', $c->source->displayPath()],
        ];
        foreach ($rows as [$label, $value]) {
            $output->writeln(sprintf('  <fg=gray>%-10s</> %s', $label, $value));
        }

        $this->usage($output, $workspace, $c);

        $output->writeln("  <fg=gray>Looked in</> ".implode(', ', array_map([Settings::class, 'tilde'], $workspace->roots))
            ." <fg=gray>(from {$workspace->origin}, {$workspace->depth} levels deep). Change with</> <fg=cyan>tokenbox config</>");
        $output->writeln('');

        return self::SUCCESS;
    }

    private function usage(OutputInterface $output, $workspace, Credential $c): void
    {
        $usage = $workspace->usage($c);
        $real = array_filter($usage, fn ($u) => !$u['rate_limit_only']);
        $rateLimit = array_filter($usage, fn ($u) => $u['rate_limit_only']);

        $output->writeln('');
        $output->writeln('  <options=bold>Used by</> <fg=gray>(from each project’s composer.json repositories and composer.lock download URLs)</>');
        if (!$real) {
            $output->writeln('  <fg=gray>No scanned project needs this host.</>');
        }
        foreach ($real as $u) {
            $output->writeln("  <fg=green>•</> <options=bold>{$u['project']->name}</> <fg=gray>{$u['project']->displayDir()}</>");
            $output->writeln("    <fg=gray>{$u['why']}</>");
        }

        if ($rateLimit) {
            $output->writeln('');
            $output->writeln('  <options=bold>Also sent for API rate limits</> <fg=gray>(public GitHub packages only; works without a token, just slower)</>');
            foreach ($rateLimit as $u) {
                $output->writeln("  <fg=gray>• {$u['project']->name} {$u['project']->displayDir()}</>");
            }
        }

        // Projects that would use this host but have their own credential for it.
        if ($c->source->scope !== AuthSource::PROJECT) {
            $overridden = array_filter($workspace->projects, fn ($p) => $p->uses($c->host)
                && !in_array($p, array_column($usage, 'project'), true));
            if ($overridden) {
                $output->writeln('');
                $output->writeln('  <options=bold>Overridden by a project auth.json</>');
                foreach ($overridden as $p) {
                    $output->writeln("  <fg=gray>• {$p->name} {$p->displayDir()}/auth.json</>");
                }
            }
        }
        $output->writeln('');
    }
}
