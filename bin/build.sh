#!/usr/bin/env bash
#
# Rebuild assets/table.min.js after editing assets/table.js. Needs Node
# (npx fetches esbuild on first run).
#
# table.min.js loads on pages with a table that sorts, searches or pages,
# so keep it small: the size is printed at the end, and the build fails
# over 3 KB gzipped.
#
# Usage: bin/build.sh

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../assets"

npx --yes esbuild@0.24.0 table.js --minify --target=es2015 --outfile=table.min.js

raw=$(wc -c < table.min.js | tr -d ' ')
gz=$(gzip -9 -c table.min.js | wc -c | tr -d ' ')
printf '\ntable.min.js  %5d bytes, %5d gzipped\n' "$raw" "$gz"

[ "$gz" -lt 3072 ] || { echo "Error: table.min.js is $gz bytes gzipped; keep it under 3072." >&2; exit 1; }
