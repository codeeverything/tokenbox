<?php

namespace Tokenbox\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tokenbox\AuthSource;
use Tokenbox\Checker;
use Tokenbox\Credential;
use Tokenbox\Health;
use Tokenbox\Settings;
use Tokenbox\Ui;
use Tokenbox\Workspace;

use function Laravel\Prompts\spin;

final class StatusCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('status')
            ->setAliases(['ls'])
            ->setDescription('Show every Composer credential, whether it works, when it expires and what uses it')
            ->addOption('offline', 'o', InputOption::VALUE_NONE, 'Don’t contact providers; just list what’s configured')
            ->addOption('warn-days', 'w', InputOption::VALUE_REQUIRED, 'Flag tokens expiring within this many days (default: warn_days setting, 14)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output (for scripts, hooks and editors)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json');
        $settings = Settings::load();
        $workspace = $this->workspace($input);
        $credentials = $workspace->credentials();
        $checker = new Checker((int) ($input->getOption('warn-days') ?? $settings->get('warn_days')));
        $warnNever = (bool) $settings->get('warn_no_expiry');

        $check = fn () => array_map(
            fn (Credential $c) => $input->getOption('offline') ? new Health(Health::UNCHECKED) : $checker->check($c, $workspace->probeUrls($c->host)),
            $credentials,
        );
        $healths = $json || $input->getOption('offline') || !$credentials
            ? $check()
            : spin($check, 'Checking '.count($credentials).' credential'.(count($credentials) === 1 ? '' : 's').'…');

        $gaps = $workspace->gaps(!$input->getOption('offline'));
        $failing = count(array_filter($healths, fn (Health $h) => in_array($h->state, [Health::EXPIRED, Health::INVALID], true)));

        if ($json) {
            $output->writeln(json_encode($this->toJson($workspace, $credentials, $healths, $gaps), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failing ? self::FAILURE : self::SUCCESS;
        }

        Ui::banner($output, 'composer credentials');

        if (!$credentials) {
            $output->writeln('  No credentials found in '.AuthSource::global()->displayPath().'.');
            $output->writeln('  Run <fg=cyan>tokenbox add</> to set one up.');
            $this->footer($output, $workspace);

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($credentials as $i => $c) {
            $h = $healths[$i];
            $rows[] = [
                Ui::badge($h),
                "<options=bold>{$c->host}</>",
                "<fg=gray>{$c->type}</>",
                $h->account ?? '<fg=gray>—</>',
                "<fg=gray>{$c->masked()}</>",
                Ui::expiry($h, $warnNever),
                Ui::sourceLabel($c->source),
                $this->usedByLabel($workspace->usage($c)),
            ];
            if ($output->isVerbose()) {
                $rows[] = ['', '<fg=gray>scopes: '.($h->scopes ? implode(', ', $h->scopes) : '—').'</>', '', '', '', '', '', ''];
            }
        }
        Ui::table($output, ['', 'Host', 'Type', 'Account', 'Token', 'Expires', 'Source', 'Used by'], $rows);

        $this->notes($output, $workspace, $credentials, $healths, $gaps, $warnNever);
        $this->summary($output, $healths, count($workspace->projects));
        $this->footer($output, $workspace);

        return $failing ? self::FAILURE : self::SUCCESS;
    }

    private function usedByLabel(array $usage): string
    {
        $real = array_values(array_filter($usage, fn ($u) => !$u['rate_limit_only']));
        $rateLimit = count($usage) - count($real);

        $label = match (count($real)) {
            0 => $rateLimit ? '' : '<fg=gray>no projects found</>',
            1 => Ui::short($real[0]['project']->name, 22),
            default => count($real).' projects',
        };
        if ($rateLimit) {
            $label .= ($label ? ' ' : '')."<fg=gray>+$rateLimit for rate limits</>";
        }

        return $label;
    }

    private function footer(OutputInterface $out, Workspace $workspace): void
    {
        $roots = implode(', ', array_map([Settings::class, 'tilde'], $workspace->roots));
        $excluded = $workspace->excluded ? ' · '.count($workspace->excluded).' excluded' : '';
        $out->writeln("  <fg=gray>Looked in $roots (from {$workspace->origin}) · {$workspace->depth} levels deep$excluded</>");
        $out->writeln('  <fg=gray>Which projects and why:</> <fg=cyan>tokenbox show <host></>  <fg=gray>· Change where to look:</> <fg=cyan>tokenbox config</>');
        $out->writeln('');
    }

    private function notes(OutputInterface $out, Workspace $workspace, array $credentials, array $healths, array $gaps, bool $warnNever): void
    {
        $lines = [];
        foreach ($credentials as $i => $c) {
            $h = $healths[$i];
            if ($h->detail && $h->state !== Health::UNCHECKED) {
                $colour = $h->isProblem() ? 'yellow' : 'gray';
                $lines[] = "<fg=$colour>!</> <options=bold>{$c->host}</> {$h->detail}";
            }
            if ($warnNever && $h->neverExpires()) {
                $lines[] = str_starts_with((string) $c->secret(), 'gho_')
                    ? "<fg=yellow>!</> <options=bold>{$c->host}</> uses the GitHub CLI’s token, which never expires; it lasts until you <fg=cyan>gh auth logout</>"
                    : "<fg=yellow>!</> <options=bold>{$c->host}</> token never expires. If it leaks it keeps working until someone notices; recreate it with an expiry";
            }
            if (in_array($h->state, [Health::EXPIRED, Health::INVALID, Health::EXPIRING], true) || ($warnNever && $h->neverExpires())) {
                $lines[] = "  <fg=gray>→</> <fg=cyan>tokenbox refresh {$c->host}</>";
            }
        }

        foreach ($workspace->sources as $source) {
            if ($source->permissionsTooOpen()) {
                $lines[] = "<fg=yellow>!</> {$source->displayPath()} is readable by other users  <fg=gray>→</> <fg=cyan>chmod 600 {$source->displayPath()}</>";
            }
        }

        foreach ($gaps as ['project' => $project, 'host' => $host]) {
            $lines[] = "<fg=yellow>!</> <options=bold>{$project->name}</> pulls from <options=bold>$host</> but no credential covers it  <fg=gray>→</> <fg=cyan>tokenbox add --host=$host</>";
        }

        if ($lines) {
            $out->writeln('');
            foreach (array_unique($lines) as $line) {
                $out->writeln("  $line");
            }
        }
    }

    private function summary(OutputInterface $out, array $healths, int $projects): void
    {
        $count = fn (string ...$states) => count(array_filter($healths, fn (Health $h) => in_array($h->state, $states, true)));
        $parts = [count($healths).' credentials', "$projects projects scanned"];
        foreach ([
            'green' => [$count(Health::OK), 'healthy'],
            'yellow' => [$count(Health::EXPIRING), 'expiring soon'],
            'red' => [$count(Health::EXPIRED, Health::INVALID), 'broken'],
            'gray' => [$count(Health::UNREACHABLE, Health::UNVERIFIED), 'unverified'],
        ] as $colour => [$n, $label]) {
            if ($n) {
                $parts[] = "<fg=$colour>$n $label</>";
            }
        }

        $out->writeln('');
        $out->writeln('  '.implode(' <fg=gray>·</> ', $parts));
    }

    private function toJson(Workspace $workspace, array $credentials, array $healths, array $gaps): array
    {
        return [
            'credentials' => array_map(fn (Credential $c, Health $h) => [
                'host' => $c->host,
                'type' => $c->type,
                'provider' => $c->provider()->label,
                'token' => $c->masked(),
                'source' => ['scope' => $c->source->scope, 'path' => $c->source->path, 'label' => $c->source->label],
                'health' => $h,
                'used_by' => array_map(fn ($u) => ['project' => $u['project']->name, 'dir' => $u['project']->dir, 'why' => $u['why'], 'rate_limit_only' => $u['rate_limit_only']], $workspace->usage($c)),
            ], $credentials, $healths),
            'gaps' => array_map(fn ($g) => ['project' => $g['project']->dir, 'host' => $g['host']], $gaps),
        ];
    }
}
