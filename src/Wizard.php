<?php

namespace Tokenbox;

/**
 * The guided "get a working credential" flow: point the user at the right
 * page to create one, collect it, and check it against the provider.
 */
final class Wizard
{
    public function __construct(private readonly Prompter $prompter)
    {
    }

    /** Walk the user through creating the credential, then collect it. */
    public function obtain(Provider $provider, string $host): string|array
    {
        $p = $this->prompter;

        if ($provider->type === 'github-oauth' && ($ghToken = self::ghCliToken($host))) {
            $use = $p->choose("You're signed in to the GitHub CLI on $host.", [
                'gh' => 'Use the GitHub CLI’s token (no expiry; revoked if you `gh auth logout`)',
                'pat' => 'Create a dedicated personal access token',
            ], 'gh');
            if ($use === 'gh') {
                return $ghToken;
            }
        }

        if ($url = $provider->createUrl($host)) {
            $p->say("  <fg=gray>Create one at</> <fg=cyan;options=underscore>$url</>");
            $p->say("  <fg=gray>{$provider->hint}</>");
            $p->say();
            if ($p->confirm('Open that page in your browser?', true)) {
                Ui::openInBrowser($url);
            }
        } else {
            $p->say("  <fg=gray>{$provider->hint}</>");
        }

        if (!$provider->fields) {
            return $p->secret('Paste the token');
        }

        $value = [];
        foreach ($provider->fields as $field) {
            $label = ucfirst(str_replace('-', ' ', $field));
            $value[$field] = in_array($field, ['password', 'token', 'consumer-secret'], true)
                ? $p->secret($label)
                : $p->ask($label, $provider->id === 'private-packagist' && $field === 'username' ? 'token' : '');
        }

        return $value;
    }

    /** Check a freshly entered credential and print the result. */
    public function verify(Credential $credential, array $probeUrls = []): Health
    {
        $health = $this->prompter->busy(
            fn () => (new Checker())->check($credential, $probeUrls),
            "Checking against {$credential->host}…",
        );

        $this->prompter->say('  '.Ui::badge($health)
            .($health->account ? "  signed in as <options=bold>{$health->account}</>" : '')
            .($health->expiresAt ? '  <fg=gray>expires</> '.Ui::expiry($health) : '')
            .($health->detail ? "  <fg=gray>{$health->detail}</>" : ''));

        return $health;
    }

    public static function ghCliToken(string $host): ?string
    {
        exec('gh auth token --hostname '.escapeshellarg($host).' 2>/dev/null', $out, $code);

        return $code === 0 && !empty($out[0]) ? trim($out[0]) : null;
    }
}
