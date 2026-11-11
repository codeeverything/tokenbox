<?php

namespace Tokenbox;

/**
 * tokenbox's own preferences, in ~/.config/tokenbox/config.json:
 *
 *   paths           folders to scan for Composer projects
 *   exclude         patterns to skip: a folder name ("legacy") or a glob on the full path ("~/Code/archive/*")
 *   depth           how many folders deep to look under each path
 *   warn_days       flag tokens expiring within this many days
 *   warn_no_expiry  flag tokens that never expire
 */
final class Settings
{
    public const DEFAULTS = [
        'paths' => [],
        'exclude' => [],
        'depth' => 3,
        'warn_days' => 14,
        'warn_no_expiry' => true,
    ];

    private array $data;

    public function __construct(public readonly string $path)
    {
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $this->data = array_merge(self::DEFAULTS, is_array($json) ? $json : []);
    }

    public static function load(): self
    {
        $base = getenv('XDG_CONFIG_HOME') ?: rtrim((string) getenv('HOME'), '/').'/.config';

        return new self(getenv('TOKENBOX_CONFIG') ?: "$base/tokenbox/config.json");
    }

    public function get(string $key): mixed
    {
        return $this->data[$key];
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0o700, true);
        }
        $changed = array_filter($this->data, fn ($v, $k) => !array_key_exists($k, self::DEFAULTS) || self::DEFAULTS[$k] !== $v, ARRAY_FILTER_USE_BOTH);
        file_put_contents($this->path, json_encode($changed ?: new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    public function displayPath(): string
    {
        return self::tilde($this->path);
    }

    public static function tilde(string $path): string
    {
        $home = (string) getenv('HOME');

        return $home !== '' && str_starts_with($path, $home) ? '~'.substr($path, strlen($home)) : $path;
    }

    public static function expand(string $path): string
    {
        return str_starts_with($path, '~') ? getenv('HOME').substr($path, 1) : $path;
    }
}
