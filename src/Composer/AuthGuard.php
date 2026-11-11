<?php

namespace Tokenbox\Composer;

use Composer\Config;
use Composer\IO\IOInterface;
use Tokenbox\AuthSource;
use Tokenbox\Checker;
use Tokenbox\Credential;
use Tokenbox\Health;
use Tokenbox\Http;
use Tokenbox\Provider;
use Tokenbox\Wizard;

/**
 * Looks at each host Composer is about to talk to, once per run. If the
 * credential for it is dead, or the host clearly needs one and there is none,
 * it offers to fix that on the spot and hands the new credential straight to
 * the running Composer process. It never stops the install: declining, a
 * non-interactive run or any internal error just lets Composer carry on.
 */
final class AuthGuard
{
    private const PUBLIC_ORIGINS = ['packagist.org', 'codeload.github.com', 'objects.githubusercontent.com', 'gitlab.com', 'bitbucket.org'];

    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(
        private readonly IOInterface $io,
        private readonly Config $config,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param string  $origin   Composer's auth key for the URL (Url::getOrigin)
     * @param ?string $repoUrl  Composer repository URL, used to test http-basic/bearer access
     */
    public function inspect(string $origin, ?string $repoUrl = null): void
    {
        if (isset($this->seen[$origin]) || in_array($origin, self::PUBLIC_ORIGINS, true) || str_contains($origin, '/')) {
            return;
        }
        $this->seen[$origin] = true;

        try {
            $this->guard($origin, $repoUrl);
        } catch (\Throwable $e) {
            $this->io->writeError("<fg=gray>tokenbox: skipped checking $origin ({$e->getMessage()})</>", true, IOInterface::VERBOSE);
        }
    }

    private function guard(string $host, ?string $repoUrl): void
    {
        $probe = $repoUrl ? [$repoUrl] : [];
        $current = $this->currentCredential($host);

        if ($current) {
            $health = (new Checker())->check($current, $probe);
            if ($health->state === Health::EXPIRING) {
                $this->io->writeError("<fg=yellow>tokenbox:</> your {$current->type} token for <options=bold>$host</> expires in {$health->daysLeft()}d. Run <fg=cyan>tokenbox refresh $host</> when convenient.");
            }
            if (!in_array($health->state, [Health::INVALID, Health::EXPIRED], true)) {
                return;
            }
            [$what, $was] = in_array($current->type, ['http-basic', 'bitbucket-oauth'], true) ? ['credentials', 'were'] : ['token', 'was'];
            $problem = $health->state === Health::EXPIRED
                ? "Your {$current->type} $what for <options=bold>$host</> expired."
                : "Your {$current->type} $what for <options=bold>$host</> ({$current->masked()}) $was rejected: revoked, expired or wrong.";
            $provider = $current->provider();
            $source = $current->source->writable() ? $current->source : AuthSource::global();
        } else {
            $provider = $this->providerNeededFor($host, $repoUrl);
            if (!$provider) {
                return;
            }
            $problem = "<options=bold>$host</> needs credentials and none are configured.";
            $source = null;
        }

        $this->offerFix($host, $problem, $provider, $source, $probe);
    }

    private function offerFix(string $host, string $problem, Provider $provider, ?AuthSource $source, array $probe): void
    {
        $io = $this->io;
        $io->writeError('');
        $io->writeError("  <fg=black;bg=cyan;options=bold> tokenbox </> $problem");

        if (!$io->isInteractive()) {
            $io->writeError("  <fg=gray>Composer will continue but may fail for $host. Fix with</> <fg=cyan>tokenbox add --host=$host</>");
            $io->writeError('');

            return;
        }

        $prompter = new ComposerPrompter($io);
        if (!$prompter->confirm("Set up new {$provider->label} credentials for $host now? Composer continues either way.", true)) {
            $io->writeError('');

            return;
        }

        $source ??= $this->chooseDestination($prompter);
        $wizard = new Wizard($prompter);
        $value = $wizard->obtain($provider, $host);
        $health = $wizard->verify(new Credential($provider->type, $host, $value, $source), $probe);

        if (in_array($health->state, [Health::INVALID, Health::EXPIRED], true)
            && !$prompter->confirm('That credential didn’t work. Use it anyway?', false)) {
            $io->writeError("  <fg=gray>Continuing without it. Try again later with</> <fg=cyan>tokenbox add --host=$host</>");
            $io->writeError('');

            return;
        }

        $source->set($provider->type, $host, $value);
        $this->apply($provider->type, $host, $value);

        if (AuthSource::env()?->credentials() && array_filter(AuthSource::env()->credentials(), fn ($c) => $c->host === $host)) {
            $io->writeError('  <fg=yellow>!</> COMPOSER_AUTH also sets this host and will override the saved one on future runs.');
        }
        $io->writeError("  <fg=green>✓</> Saved to {$source->displayPath()} and applied. Carrying on…");
        $io->writeError('');
    }

    private function chooseDestination(ComposerPrompter $prompter): AuthSource
    {
        $global = AuthSource::global();
        $project = AuthSource::project($this->projectDir, basename($this->projectDir));

        return $prompter->choose('Where should it be saved?', [
            'global' => "Global, for every project ({$global->displayPath()})",
            'project' => "This project only ({$project->displayPath()}; keep it out of git)",
        ], 'global') === 'project' ? $project : $global;
    }

    /** Make the running Composer use the new credential, mirroring BaseIO::loadConfiguration. */
    private function apply(string $type, string $host, string|array $value): void
    {
        $this->config->merge(['config' => [$type => [$host => $value]]], 'tokenbox');

        [$user, $pass] = match ($type) {
            'github-oauth' => [$value, 'x-oauth-basic'],
            'gitlab-oauth' => [$value, 'oauth2'],
            'gitlab-token' => is_array($value) ? [$value['username'], $value['token']] : [$value, 'private-token'],
            'bitbucket-oauth' => [$value['consumer-key'], $value['consumer-secret']],
            'forgejo-token' => [$value['username'], $value['token']],
            'http-basic' => [$value['username'], $value['password']],
            'bearer' => [$value, 'bearer'],
        };
        $this->io->setAuthentication($host, $user, $pass);
    }

    /**
     * Composer's own view of the credential for a host. Precedence matches
     * Composer: COMPOSER_AUTH over the project's auth.json over global.
     */
    private function currentCredential(string $host): ?Credential
    {
        $sources = array_filter([
            AuthSource::env(),
            AuthSource::project($this->projectDir, basename($this->projectDir)),
            AuthSource::global(),
        ], fn ($s) => $s && $s->exists());

        foreach ($sources as $source) {
            foreach ($source->credentials() as $c) {
                if ($c->host === $host && $c->type !== 'client-certificate') {
                    return $c;
                }
            }
        }

        return null;
    }

    /** With no credential at all, only speak up when the host plainly needs one. */
    private function providerNeededFor(string $host, ?string $repoUrl): ?Provider
    {
        if ($host === 'github.com') {
            return null; // public GitHub works anonymously; private repos are covered once a token exists
        }
        if (in_array($host, $this->config->get('github-domains'), true)) {
            return Provider::get('github-enterprise');
        }
        if (in_array($host, $this->config->get('gitlab-domains'), true)) {
            return Provider::get('gitlab');
        }
        if ($repoUrl) {
            $status = Http::request('GET', rtrim($repoUrl, '/').'/packages.json', timeout: 5)['status'];
            if (in_array($status, [401, 403], true)) {
                return str_ends_with($host, 'packagist.com') ? Provider::get('private-packagist') : Provider::get('http-basic');
            }
        }

        return null;
    }
}
