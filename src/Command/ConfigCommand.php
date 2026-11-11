<?php

namespace Tokenbox\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tokenbox\Settings;
use Tokenbox\Ui;

final class ConfigCommand extends BaseCommand
{
    private const LISTS = ['paths', 'exclude'];

    protected function configure(): void
    {
        parent::configure();
        $this->setName('config')
            ->setDescription('Show or change where tokenbox looks for projects and what it warns about')
            ->addArgument('key', InputArgument::OPTIONAL, 'paths, exclude, depth, warn_days or warn_no_expiry')
            ->addArgument('values', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'For lists: add|remove <value…>. Otherwise the new value.')
            ->setHelp(<<<'HELP'
                Examples:
                  tokenbox config                              show everything
                  tokenbox config paths add ~/Code ~/Sites     scan these folders
                  tokenbox config paths remove ~/Sites
                  tokenbox config exclude add legacy           skip any folder named "legacy"
                  tokenbox config exclude add '~/Code/archive/*'
                  tokenbox config depth 4                      look 4 folders deep
                  tokenbox config warn_days 30
                  tokenbox config warn_no_expiry false         stop flagging tokens that never expire
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = Settings::load();
        $key = $input->getArgument('key');
        $values = $input->getArgument('values');

        if ($key !== null) {
            if (!array_key_exists($key, Settings::DEFAULTS)) {
                throw new \InvalidArgumentException("Unknown setting \"$key\". Try: ".implode(', ', array_keys(Settings::DEFAULTS)));
            }
            if ($values) {
                $settings->set($key, $this->newValue($settings, $key, $values));
            }
        }

        $this->show($output, $settings, $input);

        return self::SUCCESS;
    }

    private function newValue(Settings $settings, string $key, array $values): mixed
    {
        if (in_array($key, self::LISTS, true)) {
            $action = array_shift($values);
            if (!in_array($action, ['add', 'remove'], true) || !$values) {
                throw new \InvalidArgumentException("Use: tokenbox config $key add|remove <value…>");
            }
            // Store paths with ~ so the config file is portable between machines.
            $values = array_map(fn ($v) => $key === 'paths' ? Settings::tilde(realpath(Settings::expand($v)) ?: Settings::expand($v)) : $v, $values);
            $current = $settings->get($key);

            return array_values($action === 'add'
                ? array_unique([...$current, ...$values])
                : array_diff($current, $values));
        }

        return match ($key) {
            'warn_no_expiry' => filter_var($values[0], FILTER_VALIDATE_BOOL),
            default => max(0, (int) $values[0]),
        };
    }

    private function show(OutputInterface $output, Settings $settings, InputInterface $input): void
    {
        Ui::banner($output, 'settings · '.$settings->displayPath());
        $workspace = $this->workspace($input);

        $list = fn (array $items, string $empty) => $items ? implode("\n", $items) : "<fg=gray>$empty</>";
        $rows = [
            ['paths', $list($settings->get('paths'), 'not set: using defaults')],
            ['exclude', $list($settings->get('exclude'), 'none')],
            ['depth', $settings->get('depth')],
            ['warn_days', $settings->get('warn_days')],
            ['warn_no_expiry', $settings->get('warn_no_expiry') ? 'true' : 'false'],
        ];
        foreach ($rows as [$name, $value]) {
            $lines = explode("\n", (string) $value);
            $output->writeln(sprintf('  <fg=cyan>%-15s</> %s', $name, array_shift($lines)));
            foreach ($lines as $line) {
                $output->writeln(str_repeat(' ', 18).$line);
            }
        }

        $output->writeln('');
        $output->writeln("  <options=bold>Right now</> tokenbox scans <fg=gray>(from {$workspace->origin}, plus the current folder)</>");
        foreach ($workspace->roots as $root) {
            $count = count(array_filter($workspace->projects, fn ($p) => str_starts_with($p->dir.'/', $root.'/')));
            $output->writeln('  <fg=green>•</> '.Settings::tilde($root)." <fg=gray>$count project".($count === 1 ? "" : "s")."</>");
        }
        foreach ($workspace->excluded as $dir) {
            $output->writeln('  <fg=red>✕</> <fg=gray>'.Settings::tilde($dir).' (excluded)</>');
        }
        $output->writeln('  <fg=gray>Always skipped: vendor, node_modules, dot-folders, build/dist/cache/storage.</>');
        $output->writeln('  <fg=gray>Precedence: --path, then $TOKENBOX_PATHS, then "paths" here, then ~/Code, ~/Sites, ~/Projects, ~/src.</>');
        $output->writeln('  <fg=gray>Scanning is live on every run; there is no cache to refresh.</>');
        $output->writeln('');
    }
}
