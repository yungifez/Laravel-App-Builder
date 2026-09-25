#!/usr/bin/env bash
# Creates the disposable test role and database on first volume initialization.
# Init scripts do not run against an existing volume; for that case run
# docker/postgres/create-test-database.sh (see README.md). Safe to re-run.
set -euo pipefail

psql -v ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname "$POSTGRES_DB" \
    --set=test_db="${TEST_DB_DATABASE:-control_plane_test}" \
    --set=test_user="${TEST_DB_USERNAME:-control_plane_test}" \
    --set=test_password="${TEST_DB_PASSWORD:-control_plane_test}" \
    --set=dev_db="$POSTGRES_DB" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'test_user', :'test_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'test_user')
\gexec

SELECT format('CREATE DATABASE %I OWNER %I', :'test_db', :'test_user')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'test_db')
\gexec

-- The test role must never be able to reach the development database.
SELECT format('REVOKE CONNECT ON DATABASE %I FROM PUBLIC', :'dev_db')
\gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', :'dev_db', current_user)
\gexec
SQL
