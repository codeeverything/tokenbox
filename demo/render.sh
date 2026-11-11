#!/usr/bin/env bash
# Re-record every screenshot and video on the site from a fresh demo sandbox.
# Needs: php, composer, vhs (brew install vhs).
set -euo pipefail
repo="$(cd "$(dirname "$0")/.." && pwd)"
export TOKENBOX_DEMO="${TOKENBOX_DEMO:-${TMPDIR:-/tmp}/tokenbox-demo}"
export TOKENBOX_DEMO_BIN="$repo/demo/bin"
cd "$repo"

php demo/sandbox.php "$TOKENBOX_DEMO" >/dev/null

# Install this checkout as a global Composer plugin inside the plugin-demo HOME.
(
  export HOME="$TOKENBOX_DEMO/plugin-home" COMPOSER_HOME="$TOKENBOX_DEMO/plugin-home/.composer"
  composer global config repositories.tokenbox "{\"type\":\"path\",\"url\":\"$repo\"}" -q
  composer global config allow-plugins.tokenbox/tokenbox true -q
  composer global require tokenbox/tokenbox:@dev -n -q
)

php -S localhost:8765 demo/repo-server.php >/dev/null 2>&1 &
server=$!
trap 'kill $server' EXIT

mkdir -p docs/assets/screens
for tape in ${@:-status show scan config json refresh plugin}; do
  echo "recording $tape"
  vhs "demo/tapes/$tape.tape" -q
done
