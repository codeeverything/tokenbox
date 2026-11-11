<?php

namespace Tokenbox;

/**
 * What tokenbox knows about each kind of credential: how to describe it,
 * what shape it takes in auth.json, and where to go to create a new one.
 */
final class Provider
{
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $type,
        public readonly ?string $defaultHost,
        /** @var string[] fields stored in auth.json; empty means a bare token string */
        public readonly array $fields,
        private readonly ?\Closure $createUrl,
        public readonly string $hint,
    ) {
    }

    /** @return array<string, self> */
    public static function all(): array
    {
        $github = fn (string $h) => ($h === 'github.com' ? 'https://github.com' : "https://$h")
            .'/settings/tokens/new?scopes=repo&description='.rawurlencode('Composer on '.gethostname().' '.date('Y-m-d'));
        $gitlab = fn (string $h) => "https://$h/-/user_settings/personal_access_tokens?name=composer&scopes=read_api,read_repository";

        return [
            'github' => new self('github', 'GitHub', 'github-oauth', 'github.com', [], $github,
                'Classic token with the "repo" scope, or a fine-grained token with Contents: read. Set an expiry (90 days is sensible); tokenbox warns you before it lapses.'),
            'github-enterprise' => new self('github-enterprise', 'GitHub Enterprise', 'github-oauth', null, [], $github,
                'Same as GitHub; the host is your Enterprise domain.'),
            'gitlab' => new self('gitlab', 'GitLab', 'gitlab-token', 'gitlab.com', [], $gitlab,
                'Personal access token with read_api and read_repository, with an expiry date.'),
            'bitbucket' => new self('bitbucket', 'Bitbucket', 'bitbucket-oauth', 'bitbucket.org', ['consumer-key', 'consumer-secret'],
                fn () => 'https://bitbucket.org/account/settings/', 'OAuth consumer (Workspace settings → OAuth consumers), marked private.'),
            'private-packagist' => new self('private-packagist', 'Private Packagist', 'http-basic', 'repo.packagist.com', ['username', 'password'],
                fn () => 'https://packagist.com/orgs', 'Username "token", password is an auth token from your org’s Settings → Tokens.'),
            'forgejo' => new self('forgejo', 'Forgejo / Gitea', 'forgejo-token', 'codeberg.org', ['username', 'token'],
                fn (string $h) => "https://$h/user/settings/applications", 'Access token with read:repository.'),
            'http-basic' => new self('http-basic', 'Other (HTTP basic)', 'http-basic', null, ['username', 'password'], null,
                'Satis, Repman, Artifactory, Nexus… any repository behind basic auth.'),
            'bearer' => new self('bearer', 'Other (Bearer token)', 'bearer', null, [], null,
                'Any repository that accepts an Authorization: Bearer header.'),
        ];
    }

    public static function get(string $id): self
    {
        return self::all()[$id] ?? throw new \InvalidArgumentException("Unknown provider \"$id\".");
    }

    public static function forType(string $type, string $host): self
    {
        return match (true) {
            $type === 'github-oauth' => self::get($host === 'github.com' ? 'github' : 'github-enterprise'),
            in_array($type, ['gitlab-token', 'gitlab-oauth'], true) => self::get('gitlab'),
            $type === 'bitbucket-oauth' => self::get('bitbucket'),
            $type === 'forgejo-token' => self::get('forgejo'),
            $type === 'http-basic' && str_ends_with($host, 'packagist.com') => self::get('private-packagist'),
            $type === 'bearer' => self::get('bearer'),
            default => self::get('http-basic'),
        };
    }

    public function createUrl(string $host): ?string
    {
        return $this->createUrl ? ($this->createUrl)($host) : null;
    }
}
