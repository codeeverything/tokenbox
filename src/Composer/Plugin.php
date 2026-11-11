<?php

namespace Tokenbox\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Repository\ComposerRepository;
use Composer\Util\Url;

/**
 * Hooks tokenbox into Composer: before install/update starts it checks the
 * hosts the project declares, and before every download it checks any host it
 * hasn't seen yet. Broken or missing credentials are fixed inline.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const COMMANDS = ['install', 'update', 'require', 'reinstall', 'create-project'];

    private ?AuthGuard $guard = null;
    private ?Composer $composer = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        if (getenv('TOKENBOX_DISABLE')) {
            return;
        }
        $this->composer = $composer;
        $this->guard = new AuthGuard($io, $composer->getConfig(), getcwd() ?: '.');
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $this->guard = null;
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::COMMAND => ['onCommand', 100],
            PluginEvents::PRE_FILE_DOWNLOAD => ['onPreFileDownload', 100],
        ];
    }

    /** Check every repository the root package declares, up front. */
    public function onCommand(CommandEvent $event): void
    {
        if (!$this->guard || !in_array($event->getCommandName(), self::COMMANDS, true)) {
            return;
        }
        $config = $this->composer->getConfig();
        foreach ($this->composer->getPackage()->getRepositories() as $repo) {
            $url = $repo['url'] ?? null;
            if (!is_string($url) || !preg_match('{^(https?://|[\w.-]+@)}', $url)) {
                continue; // path, artifact, package repos have no host
            }
            $httpUrl = preg_replace('{^[\w.-]+@([\w.-]+):}', 'https://$1/', $url);
            $this->guard->inspect(Url::getOrigin($config, $httpUrl), ($repo['type'] ?? '') === 'composer' ? $url : null);
        }
    }

    /** Catch-all for hosts not declared in composer.json (mirrors, dist URLs, transitive repos). */
    public function onPreFileDownload(PreFileDownloadEvent $event): void
    {
        if (!$this->guard) {
            return;
        }
        $url = $event->getProcessedUrl();
        $context = $event->getContext();
        $repoUrl = $event->getType() === 'metadata' && ($context['repository'] ?? null) instanceof ComposerRepository
            ? ($context['repository']->getRepoConfig()['url'] ?? null)
            : null;

        $this->guard->inspect(Url::getOrigin($this->composer->getConfig(), $url), $repoUrl);
    }
}
