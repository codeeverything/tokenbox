<?php

namespace Tokenbox;

/**
 * A Composer project on disk and the private hosts it needs credentials for,
 * worked out from composer.json repositories and composer.lock download URLs.
 */
final class Project
{
    /** Hosts that serve public packages; only count as "needed" when listed explicitly in "repositories". */
    private const PUBLIC_HOSTS = [
        'github.com', 'codeload.github.com', 'objects.githubusercontent.com',
        'packagist.org', 'repo.packagist.org', 'gitlab.com', 'bitbucket.org',
    ];

    /** @var array<string, string[]> host => Composer repository URLs (empty for VCS repos), from composer.json "repositories" */
    public readonly array $hosts;

    public function __construct(
        public readonly string $dir,
        public readonly string $name,
        array $hosts,
        /** @var array<string, int> host => number of composer.json repositories entries */
        public readonly array $repoCounts = [],
        /** @var array<string, string[]> host => repository paths, e.g. "acme/private-lib" */
        public readonly array $repoNames = [],
        /** @var array<string, int> host => number of locked packages downloaded from it */
        public readonly array $lockCounts = [],
    ) {
        ksort($hosts);
        $this->hosts = $hosts;
    }

    /** Does this project need credentials for $host (beyond GitHub rate limits)? */
    public function uses(string $host): bool
    {
        return isset($this->repoCounts[$host])
            || (isset($this->lockCounts[$host]) && !in_array($host, self::PUBLIC_HOSTS, true));
    }

    /** Human explanation of why this project talks to $host, or null if it doesn't. */
    public function reason(string $host): ?string
    {
        $parts = [];
        if ($n = $this->repoCounts[$host] ?? 0) {
            $names = $this->repoNames[$host] ?? [];
            $sample = $names ? ': '.implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ' +'.(count($names) - 3).' more' : '') : '';
            $parts[] = ($n === 1 ? '1 repository' : "$n repositories")." in composer.json$sample";
        }
        if ($n = $this->lockCounts[$host] ?? 0) {
            $parts[] = $n === 1 ? '1 locked package downloads from it' : "$n locked packages download from it";
            if ($host === 'github.com') {
                $parts[array_key_last($parts)] .= ' (mostly public)';
            }
        }

        return $parts ? implode(', ', $parts) : null;
    }

    public static function load(string $dir): ?self
    {
        $json = json_decode((string) @file_get_contents("$dir/composer.json"), true);
        if (!is_array($json)) {
            return null;
        }

        $hosts = $repoCounts = $repoNames = $lockCounts = [];
        foreach ((array) ($json['repositories'] ?? []) as $repo) {
            if (is_array($repo) && is_string($repo['url'] ?? null) && ($host = self::host($repo['url']))) {
                $hosts[$host][] = ($repo['type'] ?? '') === 'composer' ? $repo['url'] : null;
                $repoCounts[$host] = ($repoCounts[$host] ?? 0) + 1;
                $path = preg_replace('{^([\w.-]+@[^:]+:|[a-z]+://[^/]+/?)|\.git$}', '', $repo['url']);
                if ($path !== '') {
                    $repoNames[$host][] = $path;
                }
            }
        }

        $lock = json_decode((string) @file_get_contents("$dir/composer.lock"), true) ?: [];
        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
            $url = $package['dist']['url'] ?? $package['source']['url'] ?? null;
            if ($url && ($host = self::host($url))) {
                $lockCounts[$host] = ($lockCounts[$host] ?? 0) + 1;
            }
        }

        $hosts = array_map(fn ($urls) => array_values(array_unique(array_filter($urls))), $hosts);

        $fallback = in_array(basename($dir), ['app', 'src', 'web', 'www'], true) ? basename(dirname($dir)).'/'.basename($dir) : basename($dir);

        return new self($dir, $json['name'] ?? $fallback, $hosts, $repoCounts, $repoNames, $lockCounts);
    }

    /** Normalise https URLs, git@host:org/repo and api.github.com to the host Composer keys auth by. */
    public static function host(string $url): ?string
    {
        if (preg_match('{^[\w.-]+@([\w.-]+):}', $url, $m)) {
            $host = $m[1];
        } else {
            $host = parse_url($url, PHP_URL_HOST);
        }
        if (!$host) {
            return null;
        }
        $host = strtolower($host);

        return in_array($host, ['api.github.com', 'www.github.com'], true) ? 'github.com' : $host;
    }

    public function authSource(): AuthSource
    {
        return AuthSource::project($this->dir, $this->name);
    }

    public function displayDir(): string
    {
        $home = (string) getenv('HOME');

        return str_starts_with($this->dir, $home) ? '~'.substr($this->dir, strlen($home)) : $this->dir;
    }
}
