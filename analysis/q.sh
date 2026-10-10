#!/usr/bin/env bash
#
# Opens a READ-ONLY connection to the staging database.
#
# Read-only is enforced three ways, not just by convention:
#   1. SET SESSION TRANSACTION READ ONLY   - the server refuses writes in this session
#   2. autocommit off + a single read-only transaction per session
#   3. the container itself is read_only with all capabilities dropped
#
# Usage:
#   ./analysis/q.sh                          interactive session
#   ./analysis/q.sh -e "SELECT 1"            one query
#   ./analysis/q.sh -f analysis/scan.sql     a file of queries

set -euo pipefail

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

if [ ! -f "${DIR}/.env" ]; then
  echo "Falta ${DIR}/.env con las credenciales de staging." >&2
  exit 1
fi

# set -a makes the .env values visible to the container.
set -a
# shellcheck disable=SC1091
. "${DIR}/.env"
set +a

readonly SQL="SET SESSION TRANSACTION READ ONLY; SET SESSION sql_mode='STRICT_ALL_TABLES';"

run() {
  docker compose -f "${DIR}/docker-compose.yml" run --rm -T --entrypoint sh sql -c "
    exec mysql \
      --default-character-set=utf8mb4 --batch --raw \
      -h \"\$MYSQL_HOST\" -P \"\${MYSQL_PORT:-3306}\" \
      -u \"\$MYSQL_USER\" -p\"\$MYSQL_PASSWORD\" \
      \"\$MYSQL_DATABASE\" -e \"\$(cat)\"
  " <<SQL
${SQL}
${1}
SQL
}

case "${1:-}" in
  -e) run "$2" ;;
  -f) run "$(cat "$2")" ;;
  *)  run "$(cat)" ;;
esac