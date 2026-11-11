<?php

/*
 * Demo-only stand-in for Tokenbox\Http, loaded with `php -d auto_prepend_file=…`
 * before the autoloader so the real class is never used. It answers the
 * provider APIs tokenbox calls with canned responses for the demo tokens in
 * sandbox.sh, so the screenshots show every health state without real
 * credentials or network access. Everything else in tokenbox runs as shipped.
 */

namespace Tokenbox;

final class Http
{
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array
    {
        usleep(random_int(60_000, 180_000)); // feel like a network call

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $auth = '';
        foreach ($headers as $header) {
            if (preg_match('{^(Authorization|PRIVATE-TOKEN):\s*(?:token |Bearer |Basic )?(.+)$}i', $header, $m)) {
                $auth = $m[2];
            }
        }
        $basic = base64_decode($auth, true) ?: '';
        $days = fn (int $d) => gmdate('Y-m-d H:i:s', time() + $d * 86400 + 3600).' UTC';
        $json = fn (array $data, array $h = []) => ['status' => 200, 'headers' => $h, 'body' => json_encode($data), 'error' => null];
        $deny = ['status' => 401, 'headers' => [], 'body' => '{"message":"Bad credentials"}', 'error' => null];

        return match (true) {
            // github.com: the everyday token, about to lapse
            $host === 'api.github.com' && str_contains($auth, 'DEMOgithubEXPIRING') => $json(['login' => 'octocat'], [
                'x-oauth-scopes' => 'repo, read:org', 'github-authentication-token-expiration' => $days(6)]),
            $host === 'api.github.com' && str_contains($auth, 'DEMOgithubFRESH') => $json(['login' => 'octocat'], [
                'x-oauth-scopes' => 'repo, read:org', 'github-authentication-token-expiration' => $days(90)]),

            // GitHub Enterprise: the old token was revoked; a fresh one works
            $host === 'github.acme-corp.com' && str_contains($auth, 'DEMOenterpriseFRESH') => $json(['login' => 'sam-taylor'], [
                'x-oauth-scopes' => 'repo', 'github-authentication-token-expiration' => $days(90)]),
            $host === 'github.acme-corp.com' => $deny,

            // GitLab.com: works, but was created without an expiry date
            $host === 'gitlab.com' && $path === '/api/v4/personal_access_tokens/self' => $json([
                'scopes' => ['read_api', 'read_repository'], 'expires_at' => null, 'active' => true, 'revoked' => false]),
            $host === 'gitlab.com' => $json(['username' => 'sam-taylor']),

            // Self-hosted GitLab: healthy, expires in ~10 weeks
            $host === 'gitlab.acme-corp.com' && $path === '/api/v4/personal_access_tokens/self' => $json([
                'scopes' => ['read_api', 'read_repository'], 'expires_at' => gmdate('Y-m-d', time() + 74 * 86400), 'active' => true]),
            $host === 'gitlab.acme-corp.com' => $json(['username' => 'sam.taylor']),

            // Composer repositories: packages.json lets you in with any credential, or anonymously if public
            str_ends_with($path, '/packages.json') && in_array($host, ['wpackagist.org'], true) => $json(['packages' => []]),
            str_ends_with($path, '/packages.json') && in_array($host, ['repo.packagist.com', 'satis.acme-corp.com'], true)
                => str_contains($basic, ':') ? $json(['packages' => []]) : $deny,
            str_ends_with($path, '/packages.json') && $host === 'nova.laravel.com' => $deny,

            default => ['status' => 404, 'headers' => [], 'body' => '', 'error' => null],
        };
    }
}

/** Keep the recording machine's name out of the "new token" URLs shown on screen. */
function gethostname(): string
{
    return 'sams-macbook';
}
