<?php

namespace Tokenbox\Command;

use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tokenbox\Settings;
use Tokenbox\Workspace;

abstract class BaseCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('path', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Folder(s) to scan for Composer projects (default: $TOKENBOX_PATHS, ~/Code, cwd)');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        Prompt::setOutput($output);
        Prompt::interactive($input->isInteractive());
    }

    protected function workspace(InputInterface $input): Workspace
    {
        return Workspace::fromSettings(Settings::load(), $input->getOption('path'));
    }
}
