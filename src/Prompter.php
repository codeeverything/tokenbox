<?php

namespace Tokenbox;

/**
 * The few interactions the token wizard needs, so the same flow can run in
 * tokenbox's own terminal UI and inside a Composer process.
 * Text may contain Symfony console tags like <fg=cyan>…</>.
 */
interface Prompter
{
    public function say(string $line = ''): void;

    public function confirm(string $question, bool $default = true): bool;

    /** @param array<string, string> $options key => label; returns the chosen key */
    public function choose(string $question, array $options, ?string $default = null): string;

    public function ask(string $question, string $default = ''): string;

    public function secret(string $question): string;

    /** Run a slow task with whatever progress indicator fits. */
    public function busy(callable $task, string $message): mixed;
}
