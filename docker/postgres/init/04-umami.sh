#!/bin/bash
# Umami analytics database — runs after 02-users.sh / 03-rls-template.sql on a
# FRESH cluster ONLY (initdb.d never replays once $PGDATA exists).
#
# ⚠ The CURRENT prod cluster is already initialised, so this script WILL NOT run
# there: on that cluster the `umami` role + database are created once, by hand,
# through the founder runbook (docs/ops/deploy.md § Umami). This file exists so a
# future VIRGIN cluster comes up with analytics wired the same way.
#
# Unlike 02-users.sh (fail-closed on a missing password), this one is
# DEFENSIVE-SKIP: a cluster deployed WITHOUT Umami (the variable absent — dev, a
# stack that does not run analytics) must still init cleanly. The compose files
# pass UMAMI_DB_PASSWORD as `${UMAMI_DB_PASSWORD:-}` (empty when unset) exactly so
# this skip can fire.
#
# The `umami` role owns the `umami` database ALONE. It receives no grant on
# `amateo` and never crosses the RLS (RLS is posed table-by-table INSIDE amateo;
# a separate database is untouched by it).
set -euo pipefail

if [ -z "${UMAMI_DB_PASSWORD:-}" ]; then
  echo "04-umami.sh: UMAMI_DB_PASSWORD not set — skipping Umami role/database (cluster without analytics)."
  exit 0
fi

# Connect to the maintenance database as the bootstrap superuser. CREATE DATABASE
# cannot run inside a transaction block, so each statement runs autocommit (no
# -1/--single-transaction here, deliberately).
psql -v ON_ERROR_STOP=1 \
     -v umami_password="$UMAMI_DB_PASSWORD" \
     --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'EOSQL'
CREATE ROLE umami WITH LOGIN PASSWORD :'umami_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE DATABASE umami OWNER umami;
-- No PUBLIC connect: only the `umami` role (and cluster superusers) reach this db.
REVOKE CONNECT ON DATABASE umami FROM PUBLIC;
EOSQL

echo "04-umami.sh: created role 'umami' and database 'umami'."
