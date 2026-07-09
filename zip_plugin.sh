#!/bin/bash
# Zips the plugin for Moodle installation.
# Output: authory_tech_<version>.zip in the parent directory.
# The zip contains a single top-level folder "authory_tech" as required by Moodle.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VERSION=$(grep "plugin->release" "$SCRIPT_DIR/version.php" | sed "s/.*'\(.*\)'.*/\1/")
OUTPUT="$SCRIPT_DIR/../authory_tech_${VERSION}.zip"

# Files/dirs to exclude from the zip
EXCLUDES=(
    ".git"
    ".github"
    ".claude"
    "docker-compose.yml"
    "openapi_ml_server.yaml"
    "openapi_moodle.yaml"
    "run_open_api.sh"
    "test.md"
    "new_requirements.md"
    "CLAUDE.md"
    "zip_plugin.sh"
    "logos"
    "docs"
    "snap.png"
)

EXCLUDE_ARGS=()
for item in "${EXCLUDES[@]}"; do
    EXCLUDE_ARGS+=("--exclude=authory_tech/${item}" "--exclude=authory_tech/${item}/*")
done

PARENT="$SCRIPT_DIR/.."
SYMLINK="$PARENT/authory_tech"

cd "$PARENT"

# Create a temporary symlink so the zip contains "authory_tech/" at the top level
ln -sfn "$SCRIPT_DIR" "$SYMLINK"

rm -f "$OUTPUT"
zip -r "$OUTPUT" authory_tech "${EXCLUDE_ARGS[@]}"

rm -f "$SYMLINK"

echo "Created: $OUTPUT"
