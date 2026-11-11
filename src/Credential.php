<?php

namespace Tokenbox;

/**
 * One entry from an auth source, e.g. github-oauth -> github.com -> "ghp_…".
 */
final class Credential
{
    public function __construct(
        public readonly string $type,
        public readonly string $host,
        public readonly string|array $value,
        public readonly AuthSource $source,
    ) {
    }

    public function provider(): Provider
    {
        return Provider::forType($this->type, $this->host);
    }

    /** The primary secret (token or password) regardless of credential shape. */
    public function secret(): ?string
    {
        if (is_string($this->value)) {
            return $this->value;
        }

        return $this->value['token'] ?? $this->value['password'] ?? $this->value['consumer-secret'] ?? null;
    }

    public function username(): ?string
    {
        return is_array($this->value)
            ? ($this->value['username'] ?? $this->value['consumer-key'] ?? null)
            : null;
    }

    public function masked(): string
    {
        if ($this->type === 'client-certificate') {
            return basename((string) ($this->value['local_cert'] ?? '?'));
        }

        $secret = (string) $this->secret();
        $masked = match (true) {
            strlen($secret) >= 24 => substr($secret, 0, 4).'…'.substr($secret, -4),
            strlen($secret) >= 12 => '…'.substr($secret, -4),
            default => str_repeat('•', 6),
        };

        return $this->username() ? $this->username().':'.$masked : $masked;
    }

    public function key(): string
    {
        return $this->source->path.'#'.$this->type.'.'.$this->host;
    }
}
