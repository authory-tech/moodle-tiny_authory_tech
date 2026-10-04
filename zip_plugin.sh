#!/bin/bash
# Builds the release zip for the Moodle plugins directory.
# Output: authory_tech_<release>.zip in the parent directory, containing a single
# top-level "authory_tech/" folder as Moodle requires.
#
# The zip is made with `git archive`, so it only contains committed files, minus
# the dev-only paths marked export-ignore in .gitattributes. Uncommitted or
# untracked local files can never end up in it. Pass a ref to package something
# other than HEAD, e.g. `./zip_plugin.sh v0.3.0`.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REF="${1:-HEAD}"

cd "$SCRIPT_DIR"
if [ "$REF" = "HEAD" ] && [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Warning: uncommitted changes are NOT included; the zip is built from $REF." >&2
fi

RELEASE=$(git show "$REF:version.php" | sed -n "s/.*plugin->release *= *'\(.*\)'.*/\1/p")
OUTPUT="$SCRIPT_DIR/../authory_tech_${RELEASE}.zip"

rm -f "$OUTPUT"
git archive --format=zip --prefix=authory_tech/ -o "$OUTPUT" "$REF"

echo "Created: $OUTPUT"
