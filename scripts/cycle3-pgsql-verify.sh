#!/usr/bin/env bash
# Disposable PostgreSQL 18 for Cycle 3 RLS verification only. Not production.
set -euo pipefail

CONTAINER_NAME="${CYCLE3_PG_CONTAINER:-ekklesia-cycle3-pg}"
PG_IMAGE="${CYCLE3_PG_IMAGE:-postgres:18}"
HOST_PORT="${CYCLE3_PG_PORT:-55432}"
PG_USER="${CYCLE3_PG_SUPERUSER:-postgres}"
PG_PASS="${CYCLE3_PG_SUPERPASS:-cycle3_test_only}"
PG_DB="${CYCLE3_PG_DATABASE:-ekklesia_cycle3}"
RLS_ROLE="${TENANT_RLS_TEST_ROLE:-ekklesia_rls_test}"
RLS_PASS="${CYCLE3_RLS_ROLE_PASS:-rls_test_only}"

API_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$API_ROOT"

cleanup() {
  docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
}

if [[ "${1:-}" == "teardown" ]]; then
  cleanup
  echo "Removed container ${CONTAINER_NAME}."
  exit 0
fi

cleanup
echo "Starting ${PG_IMAGE} on 127.0.0.1:${HOST_PORT}..."
docker run -d \
  --name "$CONTAINER_NAME" \
  -e "POSTGRES_USER=${PG_USER}" \
  -e "POSTGRES_PASSWORD=${PG_PASS}" \
  -e "POSTGRES_DB=${PG_DB}" \
  -p "127.0.0.1:${HOST_PORT}:5432" \
  "$PG_IMAGE"

for i in $(seq 1 30); do
  if docker exec "$CONTAINER_NAME" pg_isready -U "$PG_USER" -d "$PG_DB" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

docker exec "$CONTAINER_NAME" pg_isready -U "$PG_USER" -d "$PG_DB"

export DB_CONNECTION=pgsql
export DB_HOST=127.0.0.1
export DB_PORT="$HOST_PORT"
export DB_DATABASE="$PG_DB"
export DB_USERNAME="$PG_USER"
export DB_PASSWORD="$PG_PASS"

php artisan migrate --force

docker exec -e PGPASSWORD="$PG_PASS" "$CONTAINER_NAME" psql -U "$PG_USER" -d "$PG_DB" -v ON_ERROR_STOP=1 -c \
  "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${RLS_ROLE}') THEN CREATE ROLE ${RLS_ROLE} LOGIN PASSWORD '${RLS_PASS}' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE; END IF; END \$\$;"
docker exec -e PGPASSWORD="$PG_PASS" "$CONTAINER_NAME" psql -U "$PG_USER" -d "$PG_DB" -v ON_ERROR_STOP=1 -c \
  "GRANT ${RLS_ROLE} TO ${PG_USER};"
docker exec -e PGPASSWORD="$PG_PASS" "$CONTAINER_NAME" psql -U "$PG_USER" -d "$PG_DB" -v ON_ERROR_STOP=1 -c \
  "GRANT CONNECT ON DATABASE ${PG_DB} TO ${RLS_ROLE};"
docker exec -e PGPASSWORD="$PG_PASS" "$CONTAINER_NAME" psql -U "$PG_USER" -d "$PG_DB" -v ON_ERROR_STOP=1 -c \
  "GRANT USAGE ON SCHEMA public TO ${RLS_ROLE};"
docker exec -e PGPASSWORD="$PG_PASS" "$CONTAINER_NAME" psql -U "$PG_USER" -d "$PG_DB" -v ON_ERROR_STOP=1 -c \
  "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ${RLS_ROLE};"
docker exec -e PGPASSWORD="$PG_PASS" "$CONTAINER_NAME" psql -U "$PG_USER" -d "$PG_DB" -v ON_ERROR_STOP=1 -c \
  "GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ${RLS_ROLE};"

export CYCLE3_PG_REQUIRED=true
export TENANT_RLS_TEST_ROLE="$RLS_ROLE"
export TENANT_RLS_REQUIRE_NONPRIVILEGED=true
export TENANT_RLS_ENABLED=true

echo "Running RLS isolation and production-check PostgreSQL tests..."
php artisan test --group=pgsql \
  Modules/Tenants/tests/Feature/TenantRlsIsolationTest.php \
  Modules/Tenants/tests/Feature/PlatformProductionCheckTest.php

echo "Recording production-check fail paths (loopback FRONTEND_URL)..."
export FRONTEND_URL=http://localhost:4200
export MARKETING_URL=https://www.example.test
php artisan platform:production-check >/tmp/cycle3-prod-check-loopback.txt 2>&1 || true
echo "platform:production-check loopback exit code: $?"

echo "Cycle 3 PostgreSQL verification finished. Run: $0 teardown"
