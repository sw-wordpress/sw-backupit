#!/usr/bin/env bash
# Starts/stops a private, throw-away MariaDB instance for tests (never touches other databases).
# Usage: bin/test-db.sh start|stop|status   (data dir: $SWBI_TEST_DB_DIR, default /tmp/swbi-test-db)
set -euo pipefail

DIR="${SWBI_TEST_DB_DIR:-/tmp/swbi-test-db}"
PORT="${SWBI_TEST_DB_PORT:-33077}"
SOCKET="$DIR/mysql.sock"

case "${1:-}" in
  start)
    if [ -S "$SOCKET" ] && mariadb-admin --socket="$SOCKET" ping >/dev/null 2>&1; then echo "already running"; exit 0; fi
    rm -rf "$DIR"; mkdir -p "$DIR/data"
    mariadb-install-db --no-defaults --datadir="$DIR/data" --auth-root-authentication-method=normal >/dev/null
    nohup mariadbd --no-defaults --datadir="$DIR/data" --socket="$SOCKET" --port="$PORT" --bind-address=127.0.0.1 \
      --pid-file="$DIR/mysql.pid" --skip-name-resolve --innodb-flush-log-at-trx-commit=0 --skip-grant-tables=0 \
      --character-set-server=utf8mb4 >"$DIR/mysqld.log" 2>&1 &
    for _ in $(seq 1 60); do
      mariadb-admin --socket="$SOCKET" ping >/dev/null 2>&1 && break; sleep 0.5
    done
    mariadb --socket="$SOCKET" -uroot -e "CREATE USER IF NOT EXISTS 'swbi'@'%' IDENTIFIED BY 'swbi'; GRANT ALL ON *.* TO 'swbi'@'%'; CREATE USER IF NOT EXISTS 'swbi'@'localhost' IDENTIFIED BY 'swbi'; GRANT ALL ON *.* TO 'swbi'@'localhost'; FLUSH PRIVILEGES;"
    echo "started on 127.0.0.1:$PORT (user swbi / swbi)"
    ;;
  stop)
    [ -f "$DIR/mysql.pid" ] && kill "$(cat "$DIR/mysql.pid")" 2>/dev/null || true
    echo stopped
    ;;
  status)
    mariadb-admin --socket="$SOCKET" ping 2>&1 || true
    ;;
  *) echo "usage: $0 start|stop|status"; exit 1;;
esac
