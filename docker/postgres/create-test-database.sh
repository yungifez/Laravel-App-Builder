#!/usr/bin/env bash
# Idempotently creates the test role and database inside a running `postgres`
# service. Use this when the data volume existed before the init script ran.
# Run from the repository root: ./docker/postgres/create-test-database.sh
set -euo pipefail

cd "$(dirname "$0")/../.."

docker compose exec -T postgres \
    bash /docker-entrypoint-initdb.d/01-create-test-database.sh
