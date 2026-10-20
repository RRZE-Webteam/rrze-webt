#!/usr/bin/env bash
# Run the suite from the WordPress root, as required by the local RRZE CLI plugin.
# Usage: bash tests/run.sh https://example.localhost:8890 [--strict] [--only=case]
set -euo pipefail

if [[ $# -lt 1 || "$1" == --* ]]; then
    echo "Usage: bash tests/run.sh <local-site-url> [--strict] [--only=case]" >&2
    exit 64
fi

plugin_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
wordpress_dir="$(cd -- "$plugin_dir/../../.." && pwd)"
site_url="$1"
shift

if [[ ! -f "$wordpress_dir/wp-load.php" ]]; then
    echo "WordPress root not found. Use wp --require=/absolute/path/tests/cli.php from your WordPress root." >&2
    exit 64
fi

cd -- "$wordpress_dir"
exec wp "--url=$site_url" "--require=$plugin_dir/tests/cli.php" rrze-webt test "$@"
