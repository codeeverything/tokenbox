<?php

namespace Tokenbox\Composer;

use Composer\IO\IOInterface;
use Tokenbox\Prompter;

/** Runs the token wizard through Composer's own IO, so it fits into composer's output. */
final class ComposerPrompter implements Prompter
{
    public function __construct(private readonly IOInterface $io)
    {
    }

    public function say(string $line = ''): void
    {
        $this->io->writeError($line);
    }

    public function confirm(string $question, bool $default = true): bool
    {
        return $this->io->askConfirmation("  $question <fg=gray>[".($default ? 'Y/n' : 'y/N').']</> ', $default);
    }

    public function choose(string $question, array $options, ?string $default = null): string
    {
        $keys = array_keys($options);
        $index = $this->io->select("  $question", array_values($options), (string) array_search($default ?? $keys[0], $keys, true));

        return (string) $keys[(int) $index];
    }

    public function ask(string $question, string $default = ''): string
    {
        $suffix = $default !== '' ? " <fg=gray>[$default]</>" : '';

        return $this->required(fn () => trim((string) $this->io->ask("  $question$suffix: ", $default)));
    }

    public function secret(string $question): string
    {
        return $this->required(fn () => trim((string) $this->io->askAndHideAnswer("  $question <fg=gray>(hidden)</>: ")));
    }

    private function required(callable $ask): string
    {
        while (($answer = $ask()) === '') {
            $this->io->writeError('  <fg=yellow>Required.</>');
        }

        return $answer;
    }

    public function busy(callable $task, string $message): mixed
    {
        $this->io->writeError("  <fg=gray>$message</>");

        return $task();
    }
}
