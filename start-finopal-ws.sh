#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

if [ -f .env ]; then
  while IFS= read -r line; do
    case "$line" in
      APP_URL=*|FINOPAL_WS_PORT=*|FINOPAL_WS_HOST=*|FINOPAL_WS_SECRET=*|FINOPAL_API_URL=*|FINOPAL_WS_URL=*)
        export "$line"
        ;;
    esac
  done < <(grep -E '^(APP_URL|FINOPAL_WS_PORT|FINOPAL_WS_HOST|FINOPAL_WS_SECRET|FINOPAL_API_URL|FINOPAL_WS_URL)=' .env | tr -d '\r' || true)
fi

export FINOPAL_WS_PORT="${FINOPAL_WS_PORT:-6001}"
export FINOPAL_WS_HOST="${FINOPAL_WS_HOST:-127.0.0.1}"
export FINOPAL_WS_SECRET="${FINOPAL_WS_SECRET:-finopal-ws-secret}"
export FINOPAL_API_URL="${FINOPAL_API_URL:-${APP_URL:-https://finonet.ir}}"

if ! command -v node >/dev/null 2>&1; then
  echo "node not found"
  exit 1
fi

if [ ! -d node_modules/ws ]; then
  npm install ws --omit=dev
fi

PID_FILE="storage/logs/ws.pid"
mkdir -p storage/logs

# Stop previous managed instance (do not fuser-kill blindly while starting)
if [ -f "$PID_FILE" ]; then
  old="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [ -n "${old:-}" ] && kill -0 "$old" 2>/dev/null; then
    kill "$old" 2>/dev/null || true
    sleep 1
  fi
  rm -f "$PID_FILE"
fi

# Free the port if something else still holds it
if command -v fuser >/dev/null 2>&1; then
  fuser -k "${FINOPAL_WS_PORT}/tcp" >/dev/null 2>&1 || true
  sleep 1
fi

echo "Starting Finopal WS on ${FINOPAL_WS_HOST}:${FINOPAL_WS_PORT} -> API $FINOPAL_API_URL"
echo $$ > "$PID_FILE"
exec node realtime/ws-server.mjs
