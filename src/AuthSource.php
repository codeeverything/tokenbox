<?php

namespace Tokenbox;

/**
 * A place Composer reads credentials from: the global auth.json, a project's
 * auth.json, or the COMPOSER_AUTH environment variable (read-only).
 */
final class AuthSource
{
    public const GLOBAL = 'global';
    public const PROJECT = 'project';
    public const ENV = 'env';

    public const TYPES = [
        'github-oauth', 'gitlab-oauth', 'gitlab-token', 'bitbucket-oauth',
        'http-basic', 'bearer', 'forgejo-token', 'client-certificate',
    ];

    private ?array $data = null;

    public function __construct(
        public readonly string $scope,
        public readonly string $path,
        public readonly string $label,
    ) {
    }

    public static function global(): self
    {
        return new self(self::GLOBAL, self::composerHome().'/auth.json', 'global');
    }

    public static function project(string $dir, string $name): self
    {
        return new self(self::PROJECT, rtrim($dir, '/').'/auth.json', $name);
    }

    public static function env(): ?self
    {
        return getenv('COMPOSER_AUTH') !== false ? new self(self::ENV, 'COMPOSER_AUTH', 'env') : null;
    }

    public static function composerHome(): string
    {
        if ($home = getenv('COMPOSER_HOME')) {
            return rtrim($home, '/');
        }
        $user = rtrim((string) getenv('HOME'), '/');
        if (is_dir($user.'/.composer')) {
            return $user.'/.composer';
        }

        return (getenv('XDG_CONFIG_HOME') ?: $user.'/.config').'/composer';
    }

    public function exists(): bool
    {
        return $this->scope === self::ENV || is_file($this->path);
    }

    public function writable(): bool
    {
        return $this->scope !== self::ENV;
    }

    /** File mode is too open if group/other can read the secrets. */
    public function permissionsTooOpen(): bool
    {
        return $this->scope !== self::ENV && is_file($this->path) && (fileperms($this->path) & 0o077) !== 0;
    }

    public function displayPath(): string
    {
        $home = (string) getenv('HOME');

        return $home !== '' && str_starts_with($this->path, $home) ? '~'.substr($this->path, strlen($home)) : $this->path;
    }

    /** @return Credential[] */
    public function credentials(): array
    {
        $out = [];
        foreach ($this->read() as $type => $hosts) {
            if (!in_array($type, self::TYPES, true) || !is_array($hosts)) {
                continue;
            }
            foreach ($hosts as $host => $value) {
                $out[] = new Credential($type, (string) $host, $value, $this);
            }
        }

        return $out;
    }

    public function set(string $type, string $host, string|array $value): void
    {
        $data = $this->read();
        $data[$type][$host] = $value;
        $this->write($data);
    }

    public function remove(string $type, string $host): void
    {
        $data = $this->read();
        unset($data[$type][$host]);
        if (empty($data[$type])) {
            unset($data[$type]);
        }
        $this->write($data);
    }

    private function read(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $json = $this->scope === self::ENV
            ? (string) getenv('COMPOSER_AUTH')
            : (is_file($this->path) ? (string) file_get_contents($this->path) : '');

        return $this->data = $json === '' ? [] : (json_decode($json, true) ?: []);
    }

    private function write(array $data): void
    {
        if (!$this->writable()) {
            throw new \RuntimeException('COMPOSER_AUTH is read-only; edit your environment instead.');
        }
        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0o700, true);
        }
        if (is_file($this->path)) {
            copy($this->path, $this->path.'.bak');
            chmod($this->path.'.bak', 0o600);
        }

        $json = json_encode($data ?: new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->path, $json."\n", LOCK_EX);
        chmod($this->path, 0o600);
        $this->data = $data;
    }
}
