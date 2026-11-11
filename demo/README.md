# Demo recordings

Everything under `docs/assets/screens/` is recorded from the real tokenbox CLI and the real Composer plugin, run inside a throwaway sandbox:

- `sandbox.php` builds a fake `HOME` with a global `auth.json`, one project-level `auth.json` and eleven Composer projects (with `composer.lock` files), plus a second `HOME` for the plugin demo.
- `mock-http.php` replaces `Tokenbox\Http` (loaded with `auto_prepend_file` before the autoloader) and answers the provider APIs with canned responses for the fake tokens, so every health state shows up without real credentials or network access. It also fixes `gethostname()` so the recording machine's name doesn't appear in "new token" URLs.
- `repo-server.php` is a tiny private Composer repository for the plugin demo. That recording uses no stubs: real Composer, the real plugin and real HTTP to `localhost:8765`.
- `bin/` puts a `tokenbox` wrapper on the `PATH`, plus `gh` and `open` stand-ins so recordings never pick up your GitHub CLI token or open browser tabs.
- `tapes/` are [VHS](https://github.com/charmbracelet/vhs) scripts, recorded at 2x for sharp screenshots.

To re-record everything (needs `php`, `composer` and `brew install vhs`):

```bash
demo/render.sh               # all tapes
demo/render.sh status show   # just some
```

Expiry dates are relative to today, so re-recording keeps "6d left" accurate.

To look around the sandbox yourself:

```bash
php demo/sandbox.php /tmp/tokenbox-demo
HOME=/tmp/tokenbox-demo/home PATH="$PWD/demo/bin:$PATH" bash -c 'cd ~/Code && tokenbox'
```
