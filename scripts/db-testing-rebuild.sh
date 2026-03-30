#!/usr/bin/env sh
set -eu

DB_CONTAINER_NAME="parking_db"
DB_USER="parking"
TEST_DB_NAME="parking_test"

# Drop and recreate the test database to guarantee a clean schema for each test run.
# This uses the maintenance database "postgres" because you cannot drop the database you are currently connected to.
docker exec "${DB_CONTAINER_NAME}" psql -U "${DB_USER}" -d postgres -v ON_ERROR_STOP=1 <<SQL
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE datname = '${TEST_DB_NAME}'
  AND pid <> pg_backend_pid();

DROP DATABASE IF EXISTS "${TEST_DB_NAME}";
CREATE DATABASE "${TEST_DB_NAME}";
SQL
