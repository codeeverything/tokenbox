<?php

namespace Tokenbox;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Output\OutputInterface;

/** Shared look and feel. */
final class Ui
{
    public static function banner(OutputInterface $out, string $subtitle): void
    {
        $out->writeln('');
        $out->writeln("  <fg=black;bg=cyan;options=bold> tokenbox </> <fg=gray>$subtitle</>");
        $out->writeln('');
    }

    public static function badge(Health $health): string
    {
        $days = $health->daysLeft();

        return match ($health->state) {
            Health::OK => '<fg=green>● ok</>',
            Health::EXPIRING => "<fg=yellow>◐ {$days}d left</>",
            Health::EXPIRED => '<fg=red;options=bold>✕ expired</>',
            Health::INVALID => '<fg=red;options=bold>✕ invalid</>',
            Health::UNREACHABLE => '<fg=gray>○ offline</>',
            Health::UNVERIFIED => '<fg=blue>? unverified</>',
            default => '<fg=gray>· unchecked</>',
        };
    }

    public static function expiry(Health $health, bool $warnNever = false): string
    {
        if ($health->neverExpires()) {
            return $warnNever ? '<fg=yellow>never ⚠</>' : '<fg=gray>never</>';
        }
        if (!$health->expiresAt) {
            return in_array($health->state, [Health::OK, Health::EXPIRING], true) ? '<fg=gray>unknown</>' : '<fg=gray>—</>';
        }
        $days = $health->daysLeft();
        $date = $health->expiresAt->format('j M Y');
        $relative = match (true) {
            $days < 0 => abs($days).'d ago',
            $days === 0 => 'today',
            default => "in {$days}d",
        };
        $colour = $days < 0 ? 'red' : ($health->state === Health::EXPIRING ? 'yellow' : 'default');

        return "<fg=$colour>$date</> <fg=gray>$relative</>";
    }

    public static function sourceLabel(AuthSource $source): string
    {
        return match ($source->scope) {
            AuthSource::GLOBAL => '<fg=cyan>global</>',
            AuthSource::ENV => '<fg=magenta>$COMPOSER_AUTH</>',
            default => '<fg=blue>'.self::short($source->label).'</>',
        };
    }

    public static function table(OutputInterface $out, array $headers, array $rows): void
    {
        $style = (new TableStyle())
            ->setHorizontalBorderChars('─')
            ->setVerticalBorderChars(' ')
            ->setCrossingChars('─', '─', '─', '─', '─', '─', '─', '─', '─')
            ->setCellHeaderFormat('<fg=gray>%s</>')
            ->setPadType(STR_PAD_RIGHT);

        $table = new Table($out);
        $table->setStyle($style);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
    }

    public static function short(string $text, int $max = 28): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    public static function openInBrowser(string $url): void
    {
        $cmd = PHP_OS_FAMILY === 'Darwin' ? 'open' : (PHP_OS_FAMILY === 'Windows' ? 'start ""' : 'xdg-open');
        exec($cmd.' '.escapeshellarg($url).' >/dev/null 2>&1');
    }
}
