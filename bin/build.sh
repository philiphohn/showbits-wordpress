#!/usr/bin/env bash
# The plugin as WordPress installs it (Plugins → Add New → Upload): dist/showbits-<version>.zip with the folder
# showbits/ inside. Checks first (syntax, translations, tests), so no broken ZIP leaves the repository.
#
#   bash bin/build.sh        (composer build)
set -euo pipefail
cd "$(dirname "$0")/.."

version=$(sed -n 's/^ \* Version: *//p' showbits/showbits.php | tr -d '[:space:]')
constant=$(sed -n "s/^const SHOWBITS_VERSION *= *'\(.*\)';/\1/p" showbits/showbits.php)
stable=$(sed -n 's/^Stable tag: *//p' showbits/readme.txt | tr -d '[:space:]')
if [ -z "$version" ] || [ "$version" != "$constant" ] || [ "$version" != "$stable" ]; then
  echo "Version mismatch: header '$version', SHOWBITS_VERSION '$constant', readme.txt Stable tag '$stable'" >&2
  exit 1
fi

composer check

mkdir -p dist
zip_file="dist/showbits-${version}.zip"
rm -f "$zip_file"
# Only the plugin: no tests, no tools, no translations (language packs from translate.wordpress.org bring them).
zip -qr -X "$zip_file" showbits -x '*.DS_Store'
echo "$zip_file ($(du -h "$zip_file" | cut -f1))"
