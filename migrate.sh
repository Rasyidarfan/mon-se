#!/usr/bin/env bash
#
# migrate.sh — Migrasi SKEMA database tanpa menghapus data.
#
# DB (data/monitoring.sqlite) TIDAK lagi di-track git, jadi `git pull` tidak akan
# menimpanya. Skrip ini menyelaraskan skema DB yang sudah berisi data dengan
# skema terbaru di lib/db.php:
#   - init_schema()    : CREATE TABLE IF NOT EXISTS (mis. foto_plang) — aman & idempoten
#   - migrate_schema() : ALTER TABLE ADD COLUMN bila kolom baru belum ada (mis. reject,
#                        kunjungan_ulang) — aman & idempoten
#   - seed_tim()       : sinkron pemetaan Tim dari data/tim_kec.json — idempoten
# import_wilayah() & seed_prelist() HANYA jalan bila tabelnya kosong, jadi DB berisi
# data TIDAK akan ter-seed ulang.
#
# Pakai (di server, dari dalam folder mon-se):
#   cd ~/mon-se.bps9702.com/mon-se
#   git pull
#   bash migrate.sh
#   bash deploy.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DB="$ROOT/data/monitoring.sqlite"

PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "✗ PHP CLI tidak ditemukan. Set PHP_BIN=/path/ke/php lalu ulangi." >&2
  exit 1
fi

# 1) Backup dulu (pengaman). Disimpan ber-timestamp; pola *.sqlite.bak-* di-gitignore.
if [[ -f "$DB" ]]; then
  BAK="$DB.bak-$(date +%Y%m%d-%H%M%S)"
  cp -p "$DB" "$BAK"
  echo "→ Backup DB  : $BAK"
else
  echo "→ DB belum ada; akan dibuat & di-seed otomatis oleh lib/db.php."
fi

# 2) Tampilkan skema sebelum (untuk catatan), lalu jalankan migrasi via lib/db.php.
echo "→ Menjalankan migrasi skema (idempoten)…"
"$PHP_BIN" -r '
  require "'"$ROOT"'/lib/helpers.php";
  $pdo = db();                       // memicu init_schema + migrate_schema + seed_tim
  // Ringkas: tampilkan kolom progres & keberadaan tabel foto_plang sebagai bukti.
  $tabs = $pdo->query("SELECT name FROM sqlite_master WHERE type=\"table\" ORDER BY name")
              ->fetchAll(PDO::FETCH_COLUMN);
  fwrite(STDERR, "  Tabel: " . implode(", ", $tabs) . "\n");
  foreach (["progres","progres_hist"] as $t) {
    $cols = array_map(fn($c)=>$c["name"], $pdo->query("PRAGMA table_info($t)")->fetchAll());
    fwrite(STDERR, "  $t: " . implode(", ", $cols) . "\n");
  }
'

echo "✓ Migrasi selesai. Data lama tetap utuh."
echo "  Lanjutkan dengan: bash deploy.sh"
