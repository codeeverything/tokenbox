<?php

namespace Tokenbox;

use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

/** Prompter for the tokenbox CLI, built on Laravel Prompts. */
final class TerminalPrompter implements Prompter
{
    public function __construct(private readonly OutputInterface $output)
    {
    }

    public function say(string $line = ''): void
    {
        $this->output->writeln($line);
    }

    public function confirm(string $question, bool $default = true): bool
    {
        return confirm($question, default: $default);
    }

    public function choose(string $question, array $options, ?string $default = null): string
    {
        return (string) select($question, $options, $default);
    }

    public function ask(string $question, string $default = ''): string
    {
        return text($question, default: $default, required: true);
    }

    public function secret(string $question): string
    {
        return password($question, required: true, hint: 'Input is hidden.');
    }

    public function busy(callable $task, string $message): mixed
    {
        return spin($task, $message);
    }
}
