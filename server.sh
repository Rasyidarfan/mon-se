#!/usr/bin/env bash
# Jalankan web monitoring Sensus Ekonomi (PHP built-in server)
set -euo pipefail

cd "$(dirname "$0")"

HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8099}"

if ! command -v php >/dev/null 2>&1; then
  echo "ERROR: php tidak ditemukan. Install PHP 8.x terlebih dahulu." >&2
  exit 1
fi

echo "================================================"
echo "  Monitoring Sensus Ekonomi - BPS Jayawijaya"
echo "================================================"
echo "  URL      : http://${HOST}:${PORT}"
echo "  Dokumen  : $(pwd)/public"
echo "  Database : $(pwd)/data/monitoring.sqlite"
echo "  (Tekan Ctrl+C untuk berhenti)"
echo "================================================"

exec php -S "${HOST}:${PORT}" -t public
