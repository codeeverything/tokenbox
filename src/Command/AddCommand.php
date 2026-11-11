<?php

namespace Tokenbox\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tokenbox\AuthSource;
use Tokenbox\Credential;
use Tokenbox\Health;
use Tokenbox\Project;
use Tokenbox\Provider;
use Tokenbox\TerminalPrompter;
use Tokenbox\Ui;
use Tokenbox\Wizard;
use Tokenbox\Workspace;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Guided creation of a credential. RefreshCommand reuses the same flow with
 * the provider, host and destination already known.
 */
class AddCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('add')
            ->setDescription('Create and save a credential for a new host, provider or project')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'One of: '.implode(', ', array_keys(Provider::all())))
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host the credential is for, e.g. github.com')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Save to this project’s auth.json instead of the global one')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the token from stdin (for scripts)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Ui::banner($output, 'add a credential');
        $workspace = $this->workspace($input);

        $host = $input->getOption('host');
        $provider = $input->getOption('provider')
            ? Provider::get($input->getOption('provider'))
            : $this->chooseProvider($host);

        $host ??= text(
            label: 'Host',
            placeholder: $provider->defaultHost ?? 'repo.example.com',
            default: $provider->defaultHost ?? '',
            required: true,
            validate: fn ($v) => preg_match('{^[a-z0-9.-]+(:\d+)?$}i', $v) ? null : 'Just the host name, e.g. github.example.com',
            hint: $provider->hint,
        );

        $source = $this->chooseDestination($input, $workspace, $host);

        return $this->obtainAndSave($input, $output, $provider, $host, $source, $workspace);
    }

    protected function obtainAndSave(InputInterface $input, OutputInterface $output, Provider $provider, string $host, AuthSource $source, Workspace $workspace): int
    {
        $wizard = new Wizard(new TerminalPrompter($output));
        $value = $input->getOption('stdin') ? $this->fromStdin($provider) : $wizard->obtain($provider, $host);
        $health = $wizard->verify(new Credential($provider->type, $host, $value, $source), $workspace->probeUrls($host));

        if (in_array($health->state, [Health::INVALID, Health::EXPIRED], true)
            && !confirm('That credential didn’t work. Save it anyway?', default: false)) {
            return self::FAILURE;
        }

        $source->set($provider->type, $host, $value);
        $output->writeln('');
        $output->writeln("  <fg=green>✓</> Saved <options=bold>{$provider->type}.$host</> to {$source->displayPath()}");
        $output->writeln('');

        return self::SUCCESS;
    }

    private function chooseProvider(?string $host): Provider
    {
        if ($host && $guess = $this->guessProvider($host)) {
            return $guess;
        }
        $id = select('What is the credential for?', array_map(fn (Provider $p) => $p->label, Provider::all()));

        return Provider::get($id);
    }

    private function guessProvider(string $host): ?Provider
    {
        return match (true) {
            $host === 'github.com' => Provider::get('github'),
            $host === 'gitlab.com' => Provider::get('gitlab'),
            $host === 'bitbucket.org' => Provider::get('bitbucket'),
            str_ends_with($host, 'packagist.com') => Provider::get('private-packagist'),
            default => null,
        };
    }

    private function chooseDestination(InputInterface $input, Workspace $workspace, string $host): AuthSource
    {
        if ($dir = $input->getOption('project')) {
            $project = Project::load(realpath($dir) ?: $dir) ?? throw new \InvalidArgumentException("No composer.json in $dir");

            return $project->authSource();
        }

        $needing = array_values(array_filter($workspace->projects, fn (Project $p) => array_key_exists($host, $p->hosts)));
        $options = ['global' => 'Global — every project on this machine '.'('.AuthSource::global()->displayPath().')'];
        foreach ($needing as $p) {
            $options[$p->dir] = "Only {$p->name} ({$p->displayDir()}/auth.json)";
        }

        $choice = count($options) === 1 ? 'global' : select('Where should it be saved?', $options, 'global',
            hint: 'Project auth.json files should be git-ignored.');

        return $choice === 'global' ? AuthSource::global() : Project::load($choice)->authSource();
    }

    private function fromStdin(Provider $provider): string|array
    {
        $raw = trim((string) stream_get_contents(STDIN));
        if (!$provider->fields) {
            return $raw;
        }
        $value = json_decode($raw, true);
        if (!is_array($value) || array_diff($provider->fields, array_keys($value))) {
            throw new \InvalidArgumentException('For '.$provider->type.' pipe JSON with: '.implode(', ', $provider->fields));
        }

        return $value;
    }
}
