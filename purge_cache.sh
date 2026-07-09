#!/bin/bash
# Purges all Moodle caches in the authory_moodle Docker container.

set -e

docker exec authory_moodle php admin/cli/purge_caches.php
echo "Moodle cache purged."
