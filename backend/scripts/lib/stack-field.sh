#!/bin/sh
# stack-field.sh <clé> [racine] — une clé de stack-resolve.sh, pour les scripts shell.
set -eu
key=$1
shift
sh "$(dirname "$0")/stack-resolve.sh" "$@" | sed -n "s/^$key=//p"
