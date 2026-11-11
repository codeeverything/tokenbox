<?php

namespace Tokenbox;

/**
 * Asks each provider whether a credential still works, who it belongs to,
 * what it can do and when it expires. Secrets are only ever sent to the
 * host they are configured for.
 */
final class Checker
{
    public function __construct(private readonly int $warnDays = Settings::DEFAULTS['warn_days'])
    {
    }

    /** @param string[] $probeUrls repository URLs known to use this host */
    public function check(Credential $credential, array $probeUrls = []): Health
    {
        try {
            $health = match ($credential->type) {
                'github-oauth' => $this->github($credential),
                'gitlab-token', 'gitlab-oauth' => $this->gitlab($credential),
                'bitbucket-oauth' => $this->bitbucket($credential),
                'forgejo-token' => $this->forgejo($credential),
                'client-certificate' => $this->certificate($credential),
                default => $this->repository($credential, $probeUrls),
            };
        } catch (\Throwable $e) {
            $health = new Health(Health::UNREACHABLE, detail: $e->getMessage());
        }

        return $health->withExpiry($health->expiresAt, $this->warnDays);
    }

    private function github(Credential $c): Health
    {
        $api = $c->host === 'github.com' ? 'https://api.github.com' : "https://{$c->host}/api/v3";
        $res = Http::request('GET', "$api/user", ['Authorization: token '.$c->secret()]);

        if ($res['status'] === 200) {
            $user = json_decode($res['body'], true);
            $scopes = array_filter(array_map('trim', explode(',', $res['headers']['x-oauth-scopes'] ?? '')));
            $expiry = $res['headers']['github-authentication-token-expiration'] ?? null;
            $classic = str_starts_with((string) $c->secret(), 'ghp_');

            return new Health(
                Health::OK,
                $user['login'] ?? null,
                $scopes,
                $expiry ? new \DateTimeImmutable($expiry) : null,
                $classic && !in_array('repo', $scopes, true) ? 'missing "repo" scope: private repos will fail' : null,
                expiryKnown: true,
            );
        }

        return $this->failure($res);
    }

    private function gitlab(Credential $c): Health
    {
        $api = "https://{$c->host}/api/v4";
        $auth = $c->type === 'gitlab-oauth' ? 'Authorization: Bearer '.$c->secret() : 'PRIVATE-TOKEN: '.$c->secret();

        $self = Http::request('GET', "$api/personal_access_tokens/self", [$auth]);
        if ($self['status'] === 401) {
            return $this->failure($self);
        }
        $user = Http::request('GET', "$api/user", [$auth]);
        $token = $self['status'] === 200 ? json_decode($self['body'], true) : [];

        if ($user['status'] !== 200 && !$token) {
            return $this->failure($user);
        }
        if (($token['revoked'] ?? false) || ($token['active'] ?? true) === false) {
            return new Health(Health::INVALID, detail: 'token revoked or inactive');
        }

        return new Health(
            Health::OK,
            json_decode($user['body'], true)['username'] ?? $c->username(),
            $token['scopes'] ?? [],
            !empty($token['expires_at']) ? new \DateTimeImmutable($token['expires_at'].' 00:00:00 UTC') : null,
            expiryKnown: (bool) $token,
        );
    }

    private function bitbucket(Credential $c): Health
    {
        $res = Http::request('POST', 'https://bitbucket.org/site/oauth2/access_token', [
            'Authorization: Basic '.base64_encode($c->value['consumer-key'].':'.$c->value['consumer-secret']),
            'Content-Type: application/x-www-form-urlencoded',
        ], 'grant_type=client_credentials');

        if ($res['status'] === 200) {
            $scopes = explode(' ', json_decode($res['body'], true)['scopes'] ?? '');

            return new Health(Health::OK, $c->username(), array_filter($scopes));
        }

        return $this->failure($res);
    }

    private function forgejo(Credential $c): Health
    {
        $res = Http::request('GET', "https://{$c->host}/api/v1/user", ['Authorization: token '.$c->secret()]);

        return $res['status'] === 200
            ? new Health(Health::OK, json_decode($res['body'], true)['login'] ?? $c->username())
            : $this->failure($res);
    }

    private function certificate(Credential $c): Health
    {
        $file = (string) ($c->value['local_cert'] ?? '');
        if (!is_readable($file) || !($cert = openssl_x509_parse((string) file_get_contents($file)))) {
            return new Health(Health::INVALID, detail: "cannot read certificate $file");
        }

        return new Health(
            Health::OK,
            $cert['subject']['CN'] ?? null,
            expiresAt: (new \DateTimeImmutable())->setTimestamp($cert['validTo_time_t']),
            expiryKnown: true,
        );
    }

    /** http-basic / bearer: there's no "who am I" API, so fetch packages.json and see if we're let in. */
    private function repository(Credential $c, array $probeUrls): Health
    {
        $header = $c->type === 'bearer'
            ? 'Authorization: Bearer '.$c->secret()
            : 'Authorization: Basic '.base64_encode($c->username().':'.$c->secret());

        $candidates = array_map(fn ($u) => rtrim($u, '/').'/packages.json', $probeUrls);
        $candidates[] = "https://{$c->host}/packages.json";

        $last = null;
        foreach (array_unique($candidates) as $url) {
            $port = parse_url($url, PHP_URL_PORT);
            if (parse_url($url, PHP_URL_HOST).($port ? ":$port" : '') !== $c->host) {
                continue; // never send a secret anywhere but its own host
            }
            $last = Http::request('GET', $url, [$header]);
            if ($last['status'] === 200) {
                return new Health(Health::OK, $c->username());
            }
            if (in_array($last['status'], [401, 403], true)) {
                return $this->failure($last);
            }
        }

        return $last && $last['status'] === 0
            ? $this->failure($last)
            : new Health(Health::UNVERIFIED, $c->username(), detail: 'no packages.json found to test against');
    }

    private function failure(array $res): Health
    {
        return match (true) {
            $res['status'] === 0 => new Health(Health::UNREACHABLE, detail: $res['error']),
            in_array($res['status'], [401, 403], true) => new Health(Health::INVALID, detail: "HTTP {$res['status']}: rejected (revoked, expired or wrong)"),
            default => new Health(Health::UNVERIFIED, detail: "HTTP {$res['status']}"),
        };
    }
}
