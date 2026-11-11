<?php

namespace Tokenbox;

/**
 * Everything tokenbox can see: auth sources, the credentials in them and the
 * Composer projects under the configured roots.
 */
final class Workspace
{
    private const SKIP = ['vendor', 'node_modules', '.git', '.idea', 'storage', 'cache', 'build', 'dist'];

    /** @var Project[] */
    public readonly array $projects;

    /** @var AuthSource[] */
    public readonly array $sources;

    /** @var string[] directories skipped because they matched an exclude pattern */
    public readonly array $excluded;

    /**
     * @param string[] $roots    absolute folders to scan
     * @param string   $origin   where the roots came from, for display
     * @param string[] $exclude  folder names or path globs to skip
     */
    public function __construct(
        public readonly array $roots,
        public readonly string $origin = 'given',
        public readonly int $depth = 3,
        public readonly array $exclude = [],
    ) {
        $projects = [];
        $excluded = [];
        foreach ($roots as $root) {
            foreach ($this->find($root, $depth, $excluded) as $dir) {
                $projects[fileinode("$dir/composer.json")] ??= Project::load($dir);
            }
        }
        $this->projects = array_values(array_filter($projects));
        $this->excluded = array_values(array_unique($excluded));

        $sources = [AuthSource::global()];
        if ($env = AuthSource::env()) {
            $sources[] = $env;
        }
        foreach ($this->projects as $project) {
            if ($project->authSource()->exists()) {
                $sources[] = $project->authSource();
            }
        }
        $this->sources = $sources;
    }

    /**
     * Where to look, first match wins: --path, $TOKENBOX_PATHS, "paths" in the
     * config file, then whichever of ~/Code, ~/Sites, ~/Projects, ~/src exist.
     * The current directory is always added.
     */
    public static function fromSettings(Settings $settings, array $cliPaths = []): self
    {
        $home = (string) getenv('HOME');
        [$paths, $origin] = match (true) {
            (bool) $cliPaths => [$cliPaths, '--path'],
            (bool) getenv('TOKENBOX_PATHS') => [explode(PATH_SEPARATOR, (string) getenv('TOKENBOX_PATHS')), '$TOKENBOX_PATHS'],
            (bool) $settings->get('paths') => [$settings->get('paths'), $settings->displayPath()],
            default => [array_filter(["$home/Code", "$home/Sites", "$home/Projects", "$home/src"], 'is_dir'), 'defaults'],
        };
        $paths[] = getcwd();

        // Key by inode so ~/Code and ~/code on a case-insensitive disk count once.
        $roots = [];
        foreach ($paths as $path) {
            if ($real = realpath(Settings::expand($path))) {
                $roots[fileinode($real)] ??= $real;
            }
        }

        return new self(array_values($roots), $origin, (int) $settings->get('depth'), $settings->get('exclude'));
    }

    /** @return Credential[] */
    public function credentials(): array
    {
        return array_merge(...array_map(fn (AuthSource $s) => $s->credentials(), $this->sources));
    }

    /**
     * Projects that would use this credential, and why. Project-level auth
     * applies to its own project; global/env auth applies wherever the project
     * has no override. GitHub is special: Composer sends the github.com token
     * on every GitHub API call, so projects with only public GitHub packages
     * still use it, for rate limits.
     *
     * @return array<array{project: Project, why: string, rate_limit_only: bool}>
     */
    public function usage(Credential $credential): array
    {
        $host = $credential->host;
        $usage = [];
        foreach ($this->projects as $p) {
            $why = $p->reason($host);
            if (!$why) {
                continue;
            }
            if ($credential->source->scope === AuthSource::PROJECT
                ? $credential->source->path !== $p->authSource()->path
                : $this->projectOverrides($p, $host)) {
                continue;
            }
            $rateLimitOnly = $host === 'github.com' && !isset($p->repoCounts[$host]);
            $usage[] = ['project' => $p, 'why' => $rateLimitOnly ? "$why (public; token only lifts API rate limits)" : $why, 'rate_limit_only' => $rateLimitOnly];
        }

        return $usage;
    }

    /** @return Project[] projects that need this credential for more than rate limits */
    public function usedBy(Credential $credential): array
    {
        return array_column(array_filter($this->usage($credential), fn ($u) => !$u['rate_limit_only']), 'project');
    }

    /** Repository URLs that go through this host, for probing http-basic/bearer credentials. */
    public function probeUrls(string $host): array
    {
        $urls = array_merge([], ...array_map(fn (Project $p) => $p->hosts[$host] ?? [], $this->projects));

        return array_values(array_unique($urls));
    }

    /** @return Credential[] credentials (any source) that cover $host for $project */
    public function credentialsFor(Project $project, string $host): array
    {
        return array_values(array_filter($this->credentials(), fn (Credential $c) => $c->host === $host
            && ($c->source->scope !== AuthSource::PROJECT || $c->source->path === $project->authSource()->path)));
    }

    /**
     * Hosts a project declares that no credential covers. Composer repositories
     * that answer anonymously (wpackagist, public Satis…) are skipped when $online.
     *
     * @return array<array{project: Project, host: string}>
     */
    public function gaps(bool $online): array
    {
        static $public = [];
        $gaps = [];
        foreach ($this->projects as $project) {
            foreach ($project->hosts as $host => $urls) {
                if ($host === 'github.com' || $this->credentialsFor($project, $host)) {
                    continue;
                }
                if ($online) {
                    $public[$host] ??= self::answersAnonymously($host, $urls);
                    if ($public[$host]) {
                        continue;
                    }
                }
                $gaps[] = ['project' => $project, 'host' => $host];
            }
        }

        return $gaps;
    }

    private static function answersAnonymously(string $host, array $urls): bool
    {
        foreach ($urls as $url) {
            if (Http::request('GET', rtrim($url, '/').'/packages.json', timeout: 5)['status'] === 200) {
                return true;
            }
        }

        return false;
    }

    private function projectOverrides(Project $project, string $host): bool
    {
        foreach ($project->authSource()->exists() ? $project->authSource()->credentials() : [] as $c) {
            if ($c->host === $host) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] directories containing composer.json */
    private function find(string $root, int $depth, array &$excluded): array
    {
        if ($this->isExcluded($root)) {
            $excluded[] = $root;

            return [];
        }
        $found = is_file("$root/composer.json") ? [$root] : [];
        if ($depth === 0) {
            return $found;
        }
        foreach (@scandir($root) ?: [] as $entry) {
            if ($entry[0] === '.' || in_array($entry, self::SKIP, true) || !is_dir("$root/$entry") || is_link("$root/$entry")) {
                continue;
            }
            array_push($found, ...$this->find("$root/$entry", $depth - 1, $excluded));
        }

        return $found;
    }

    /** A pattern without a slash matches a folder name anywhere; with one, a glob on the full path. */
    private function isExcluded(string $dir): bool
    {
        foreach ($this->exclude as $pattern) {
            $matches = str_contains($pattern, '/')
                ? fnmatch(Settings::expand(rtrim($pattern, '/')), $dir)
                : fnmatch($pattern, basename($dir));
            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
