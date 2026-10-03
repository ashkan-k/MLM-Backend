#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

export FINOPAL_WS_PORT="${FINOPAL_WS_PORT:-6001}"
export FINOPAL_WS_SECRET="${FINOPAL_WS_SECRET:-abba6c683f4a9422cca1724c5cd8d535}"
export FINOPAL_API_URL="${FINOPAL_API_URL:-https://bamiz.ir}"

if ! command -v node >/dev/null 2>&1; then
  echo "node not found"
  exit 1
fi

if [ ! -d node_modules/ws ]; then
  npm install ws --omit=dev
fi

echo "Starting Finopal WS on :$FINOPAL_WS_PORT -> API $FINOPAL_API_URL"
exec node realtime/ws-server.mjs
