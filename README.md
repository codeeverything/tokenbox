# tokenbox

See, check, refresh and create your Composer auth tokens across every repo and account.

```
  tokenbox  composer credentials

─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
              Host                   Type           Account   Token       Expires    Source     Used by
─────────────────────────────────────────────────────────────────────────────────────────────────────────────────
  ✕ invalid   github.example.co.uk   github-oauth   —         ghp_…a1B2   —          global     no projects found
  ◐ 6d left   github.com             github-oauth   octocat   ghp_…c3D4   17 Nov     global     15 projects +6 for rate limits
  ● ok        repo.packagist.com     http-basic     token     …9f2a       unknown    acme/api   acme/api
─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

  ! github.example.co.uk HTTP 401: rejected (revoked, expired or wrong)
    → tokenbox refresh github.example.co.uk
  ! ~/.composer/auth.json is readable by other users  → chmod 600 ~/.composer/auth.json

  3 credentials · 23 projects scanned · 1 healthy · 1 expiring soon · 1 broken
  Looked in ~/Code (from defaults) · 3 levels deep
  Which projects and why: tokenbox show <host>  · Change where to look: tokenbox config
```

## Install

```bash
composer install
ln -s "$PWD/bin/tokenbox" /usr/local/bin/tokenbox   # or add bin/ to your PATH
```

Once published, one package gives you both the CLI and the Composer plugin:

```bash
composer global config allow-plugins.tokenbox/tokenbox true
composer global require tokenbox/tokenbox
```

To try the plugin from this checkout before it's published:

```bash
composer global config repositories.tokenbox '{"type":"path","url":"~/Code/tokenbox"}'
composer global config allow-plugins.tokenbox/tokenbox true
composer global require tokenbox/tokenbox:@dev
```

## Commands

| Command | What it does |
| --- | --- |
| `tokenbox` / `tokenbox status` | Every credential, whether it works, who it signs in as, scopes (`-v`), expiry and which projects use it. Exits `1` if any are expired or invalid. |
| `tokenbox status --offline` | Same, without contacting any provider. |
| `tokenbox status --json` | Machine-readable, for hooks, CI and editor integrations. |
| `tokenbox show <host>` | One credential in full: health, scopes, expiry, where it's stored, and **every project that uses it and why** (which repositories in composer.json, how many locked packages). Also lists projects that override it with their own auth.json. |
| `tokenbox scan` | Composer projects under your roots and the private hosts each needs (✓ covered, ✕ missing, ○ public). |
| `tokenbox config` | Where tokenbox looks and what it warns about. See below. |
| `tokenbox add` | Guided: pick provider → host → global or per-project → opens the provider’s “new token” page pre-filled → paste → verified → saved. |
| `tokenbox refresh [host]` | Shows the current token’s health, then the same guided flow, replacing it in place. |
| `tokenbox remove [host]` | Removes an entry (keeps `auth.json.bak`). |

## Where tokenbox looks (and doesn't)

The first of these that is set wins, and the current folder is always added:

1. `--path <dir>` on the command line (repeatable)
2. `$TOKENBOX_PATHS` (colon-separated)
3. `paths` in `~/.config/tokenbox/config.json`
4. whichever of `~/Code`, `~/Sites`, `~/Projects`, `~/src` exist

Every run says where it looked and why. Scanning happens live on each run, so there is no cache to refresh.

```bash
tokenbox config                                  # show settings and what's being scanned right now
tokenbox config paths add ~/Code ~/Sites
tokenbox config paths remove ~/Sites
tokenbox config exclude add legacy               # skip any folder named "legacy"
tokenbox config exclude add '~/Code/archive/*'   # or a glob on the full path
tokenbox config depth 4                          # how many folders deep to look (default 3)
tokenbox config warn_days 30                     # expiry warning window (default 14)
tokenbox config warn_no_expiry false             # stop flagging tokens that never expire
```

`vendor`, `node_modules`, dot-folders and `build`/`dist`/`cache`/`storage` are always skipped.

## “Used by”

A project uses a host if its `composer.json` lists a repository there, or its `composer.lock` downloads packages from it. `tokenbox show <host>` lists each project with the reason, e.g. `2 repositories in composer.json: acme/private-lib, acme/billing`.

GitHub is a special case. Composer sends your github.com token on every GitHub API call, so projects with only public GitHub packages still use it, but only to lift rate limits. Those are counted separately ("+6 for rate limits") because they would still work without a token.

## Tokens that never expire

If the provider says a token has no expiry date (GitHub classic tokens or `gh` CLI tokens, GitLab tokens without `expires_at`), tokenbox shows `never ⚠` and suggests recreating it with one. A leaked token that never expires keeps working until someone notices. Tokens created with an expiry get a warning `warn_days` before they lapse, so short-lived tokens aren't a chore. If you choose otherwise, turn this off with `tokenbox config warn_no_expiry false`.

For http-basic and bearer credentials the expiry isn't knowable, so they show `unknown` rather than `never`.

Scripted add: `echo "$TOKEN" | tokenbox add --provider=github --host=github.com --stdin -n`
(for multi-field types pipe JSON, e.g. `{"username":"token","password":"…"}`).

## Where credentials come from

Composer reads auth from three places, and tokenbox shows all of them:

1. **Global**: `$COMPOSER_HOME/auth.json` (usually `~/.composer/auth.json` or `~/.config/composer/auth.json`).
2. **Project**: `auth.json` next to a project’s `composer.json`. This overrides global for that project.
3. **`COMPOSER_AUTH`** environment variable (shown, read-only).

“Used by” is worked out from each project’s `composer.json` `repositories` and the download URLs in `composer.lock`. Every project counts as using `github.com`, because Composer uses that token for API rate limits.

## How health is checked

Secrets are only ever sent to the host they are configured for.

| Type | Check | Expiry known? |
| --- | --- | --- |
| `github-oauth` (github.com & Enterprise) | `GET /user` → login, `X-OAuth-Scopes`; warns if a classic token lacks `repo` | ✓ from `github-authentication-token-expiration` |
| `gitlab-token` / `gitlab-oauth` | `GET /personal_access_tokens/self` + `/user` | ✓ `expires_at`, revoked/active |
| `bitbucket-oauth` | client-credentials grant | — |
| `forgejo-token` | `GET /api/v1/user` | — |
| `http-basic` / `bearer` (Private Packagist, Satis, Repman, Artifactory…) | `GET packages.json` on the project’s repository URL | — |
| `client-certificate` | Reads the local cert | ✓ `validTo` |

For GitHub, `add`/`refresh` will offer the GitHub CLI’s token if `gh` is signed in to that host. That token never expires, but it stops working if you `gh auth logout`.

Every write keeps an `auth.json.bak` and sets the file to `chmod 600`.

## Composer plugin: fixing auth walls mid-install

With tokenbox installed globally, `composer install`/`update`/`require` gets a guard:

- **Before the command runs**, every repository in your `composer.json` is checked once.
- **Before each download**, any host not seen yet is checked. This covers mirrors, dist URLs and repositories pulled in by other packages.

If a credential is rejected or expired, or a host clearly needs one and there is none, you get this instead of a failed install:

```
   tokenbox  Your github-oauth token for github.example.co.uk (ghp_…a1B2) was rejected: revoked, expired or wrong.
  Set up new GitHub Enterprise credentials for github.example.co.uk now? Composer continues either way. [Y/n]
  Create one at https://github.example.co.uk/settings/tokens/new?scopes=repo&description=…
  Paste the token (hidden):
  ● ok  signed in as octocat  expires 9 Feb 2027 in 90d
  ✓ Saved to ~/.composer/auth.json and applied. Carrying on…
```

The new credential is saved to `auth.json` **and** handed to the running Composer process, so the install continues without a restart. Saying no, running with `-n`/in CI (where you get a one-line hint instead), or any internal tokenbox error never stops Composer. Set `TOKENBOX_DISABLE=1` to turn the plugin off.

"Clearly needs one" means: a GitHub Enterprise or self-hosted GitLab domain (from Composer's `github-domains`/`gitlab-domains`), or a Composer repository whose `packages.json` returns 401/403 anonymously. Public github.com is left alone without a token, because it works anonymously.

## Layout

```
bin/tokenbox            entry point (Symfony Console)
src/AuthSource.php      read/write one auth.json or COMPOSER_AUTH
src/Credential.php      one type+host entry, masking
src/Provider.php        per-provider labels, create-token URLs, field shapes
src/Checker.php         live health checks → Health
src/Project.php         composer.json/lock → hosts a project needs
src/Workspace.php       discovery: sources, projects, coverage and gaps
src/Settings.php        ~/.config/tokenbox/config.json
src/Wizard.php          guided "create → paste → verify" flow, shared by CLI and plugin
src/Prompter.php        the interface it talks through (TerminalPrompter, Composer\ComposerPrompter)
src/Composer/Plugin.php hooks: command, pre-file-download
src/Composer/AuthGuard.php  decides when to step in, applies new credentials to the running Composer
src/Command/*           status, show, scan, config, add, refresh, remove
```

## Roadmap

**v0.2: polish**
- `tokenbox doctor`: fix file permissions, de-duplicate entries shadowed by project auth, flag tokens committed to git (`git ls-files auth.json`) and project `auth.json` missing from `.gitignore`.
- Concurrent checks (`curl_multi`) and a short-lived cache so `status` is instant.
- Detect GitHub org SSO: a token that works for `/user` but returns 403 for an org’s repos needs SSO authorisation. Link straight to the authorise page.
- `--watch` / `tokenbox notify`: a launchd/cron job that sends a macOS notification N days before expiry.

**v0.3: real “refresh”**
- GitLab: `POST /personal_access_tokens/self/rotate` rotates in place with no browser trip.
- GitHub fine-grained tokens can’t be created by API, so keep the guided browser flow, but use `gh auth refresh -s repo` when using the gh token.
- Private Packagist: create and rotate tokens via its API, using an org API key.
- Optionally store secrets in macOS Keychain / 1Password (`op`) and render `auth.json` from them.

**v0.4: hook into dev tools**
- **Composer plugin commands**: expose the CLI as `composer tokens`, `composer tokens:refresh` (the auth guard is already in place).
- **VCS-driver coverage**: GitHub/GitLab API calls made by `vcs` repositories during `update` don't fire `pre-file-download`. They're covered by the up-front check of `composer.json` repositories, but not when they come from a dependency's own repositories.
- **Claude Code / Codex / other agents**: an MCP server (`tokenbox mcp`) exposing `list_credentials`, `check_credential`, `scan_projects` and a guided `refresh_credential` that hands the browser step back to the human. Secrets never go to the model: tools return masked values only. A Claude Code plugin could also bundle a `SessionStart` hook that runs `tokenbox status --json` and warns when a project you open has a broken or expiring token.
- **VS Code / Cursor / JetBrains**: a status-bar item driven by `tokenbox status --json` (green / amber “expires in 6d” / red), with commands that open `tokenbox refresh` in the integrated terminal. A problem-matcher can catch Composer 401s in task output.
- **CI**: `tokenbox status --json --warn-days=30` as a scheduled job to catch expiring deploy tokens before builds break.

The `--json` output is the stable contract all of these build on.
