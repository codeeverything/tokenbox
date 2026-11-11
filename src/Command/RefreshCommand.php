<?php

namespace Tokenbox\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tokenbox\Checker;
use Tokenbox\Credential;
use Tokenbox\Ui;
use Tokenbox\Workspace;

use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

final class RefreshCommand extends AddCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('refresh')
            ->setAliases(['renew', 'rotate'])
            ->setDescription('Replace an existing credential with a freshly created one')
            ->addArgument('host', InputArgument::OPTIONAL, 'Host of the credential to replace');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Ui::banner($output, 'refresh a credential');
        $workspace = $this->workspace($input);
        $credential = self::pick($workspace, $input->getArgument('host'), 'Which credential do you want to refresh?');

        if (!$credential->source->writable()) {
            $output->writeln('  <fg=yellow>!</> That one comes from $COMPOSER_AUTH; update it where you set that variable.');

            return self::FAILURE;
        }

        $health = spin(fn () => (new Checker())->check($credential, $workspace->probeUrls($credential->host)), 'Checking the current one…');
        $output->writeln("  Current: ".Ui::badge($health).($health->account ? " {$health->account}" : '')
            ."  <fg=gray>{$credential->masked()} in {$credential->source->displayPath()}</>");
        $output->writeln('');

        return $this->obtainAndSave($input, $output, $credential->provider(), $credential->host, $credential->source, $workspace);
    }

    public static function pick(Workspace $workspace, ?string $host, string $label): Credential
    {
        $credentials = $workspace->credentials();
        if ($host) {
            $credentials = array_values(array_filter($credentials, fn (Credential $c) => $c->host === $host));
        }
        if (!$credentials) {
            throw new \InvalidArgumentException($host ? "No credential for $host. Try: tokenbox add --host=$host" : 'No credentials configured yet.');
        }
        if (count($credentials) === 1) {
            return $credentials[0];
        }

        // String keys: given a plain list, select() returns the label instead of the key.
        $options = [];
        foreach ($credentials as $c) {
            $options[$c->key()] = "{$c->host}  ·  {$c->type}  ·  {$c->source->label}";
        }
        $key = select($label, $options);

        return array_values(array_filter($credentials, fn (Credential $c) => $c->key() === $key))[0];
    }
}
