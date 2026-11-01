#!/usr/bin/env bash
# WordPress.org's Plugin Check (https://wordpress.org/plugins/plugin-check/, source github.com/WordPress/plugin-check)
# against the plugin, in a throwaway WordPress (tests/e2e/wordpress.sh): the directory's requirements and best
# practices, static checks and the runtime ones. Fails on any error or warning.
#
#   bash tests/e2e/plugin-check.sh [path to an unpacked plugin-check]     (from wordpress/)
#
# Without a path it downloads the release build named below from GitHub (wordpress.org's own download works too:
# https://downloads.wordpress.org/plugin/plugin-check.<version>.zip).
set -euo pipefail
cd "$(dirname "$0")/../.."
source tests/e2e/wordpress.sh

VERSION="${PLUGIN_CHECK_VERSION:-2.1.0}"
WORK=$(mktemp -d)
trap 'wordpress_down; rm -rf "$WORK"' EXIT

CHECKER="${1:-}"
if [ -z "$CHECKER" ]; then
  curl -fsSL -o "$WORK/plugin-check.zip" "https://github.com/WordPress/plugin-check/releases/download/$VERSION/plugin-check.zip"
  unzip -q "$WORK/plugin-check.zip" -d "$WORK"
  CHECKER="$WORK/plugin-check"
fi
grep -q "Version: $VERSION" "$CHECKER/plugin.php" || echo "Note: $CHECKER is not Plugin Check $VERSION" >&2

# Plugin Check writes its runtime drop-in into wp-content: its own copy, writable by WordPress.
cp -r "$CHECKER" "$WORK/checker"
chmod -R a+rX "$WORK/checker"
wordpress_up -v "$WORK/checker:/var/www/html/wp-content/plugins/plugin-check"
bash tests/e2e/wp.sh plugin activate plugin-check >/dev/null

# Every category, low severity included; the runtime checks need Plugin Check's own CLI bootstrap.
bash tests/e2e/wp.sh plugin check showbits --include-low-severity-errors --include-low-severity-warnings \
  --require=./wp-content/plugins/plugin-check/cli.php --format=table | tee "$WORK/result.txt"
if grep -qE '\b(ERROR|WARNING)\b' "$WORK/result.txt"; then
  echo "Plugin Check found problems (above)." >&2
  exit 1
fi
echo "Plugin Check $VERSION: no errors, no warnings."
