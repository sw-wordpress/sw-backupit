#!/usr/bin/env bash
# Starts/stops a local S3 mock (moto) for storage integration tests. Needs a venv with moto[server]:
#   python3 -m venv /tmp/swbi-moto && /tmp/swbi-moto/bin/pip install 'moto[server]'
set -euo pipefail
VENV="${SWBI_MOTO_VENV:-/tmp/swbi-moto}"
PORT="${SWBI_TEST_S3_PORT:-33099}"
case "${1:-}" in
  start)
    nohup "$VENV/bin/moto_server" -H 127.0.0.1 -p "$PORT" >/tmp/swbi-moto.log 2>&1 &
    echo $! >/tmp/swbi-moto.pid
    for _ in $(seq 1 40); do curl -s "http://127.0.0.1:$PORT/" >/dev/null 2>&1 && break; sleep 0.5; done
    echo "S3 mock on http://127.0.0.1:$PORT"
    ;;
  stop) [ -f /tmp/swbi-moto.pid ] && kill "$(cat /tmp/swbi-moto.pid)" 2>/dev/null || true; echo stopped ;;
  *) echo "usage: $0 start|stop"; exit 1 ;;
esac
