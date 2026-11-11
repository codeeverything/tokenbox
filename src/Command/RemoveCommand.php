<?php

namespace Tokenbox\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tokenbox\Ui;

use function Laravel\Prompts\confirm;

final class RemoveCommand extends BaseCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('remove')
            ->setAliases(['rm'])
            ->setDescription('Remove a credential from its auth.json (a .bak copy is kept)')
            ->addArgument('host', InputArgument::OPTIONAL, 'Host of the credential to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Ui::banner($output, 'remove a credential');
        $c = RefreshCommand::pick($this->workspace($input), $input->getArgument('host'), 'Which credential do you want to remove?');

        if (!$c->source->writable()) {
            $output->writeln('  <fg=yellow>!</> That one comes from $COMPOSER_AUTH; unset it in your environment.');

            return self::FAILURE;
        }
        if (!confirm("Remove {$c->type}.{$c->host} ({$c->masked()}) from {$c->source->displayPath()}?", default: false)) {
            return self::SUCCESS;
        }

        $c->source->remove($c->type, $c->host);
        $output->writeln("  <fg=green>✓</> Removed. Previous file kept at {$c->source->displayPath()}.bak");
        $output->writeln('  <fg=gray>This doesn’t revoke the token at the provider. Do that from its settings page if it’s compromised.</>');
        $output->writeln('');

        return self::SUCCESS;
    }
}
