#!/usr/bin/env bash
#
# deploy.sh — Salin isi mon-se/public ke public_html agar bisa diakses publik.
#
# Layout hosting (mon-se & public_html SEJAJAR di dalam folder domain):
#   ~/mon-se.bps9702.com/
#   ├── mon-se/           <- repo ini (git pull di sini)
#   │   ├── lib/          <- TIDAK disalin (tetap di luar web root, lebih aman)
#   │   ├── data/         <- TIDAK disalin (DB & PIN tetap privat)
#   │   └── public/       <- isinya disalin ke public_html/
#   └── public_html/      <- document root (https://mon-se.bps9702.com/)
#
# File PHP di public memanggil  __DIR__ . '/../lib/helpers.php'.
# Setelah berada di public_html/, '../lib' menunjuk ke folder domain (salah),
# jadi skrip menulis ulang menjadi '/../mon-se/lib/' pada SALINAN saja:
#   public_html/../mon-se/lib  =  ~/mon-se.bps9702.com/mon-se/lib  ✔
#
# Jalankan via SSH / cPanel Terminal, dari dalam folder mon-se:
#   cd ~/mon-se.bps9702.com/mon-se
#   git pull
#   bash deploy.sh
#
set -euo pipefail

# Lokasi skrip = root repo mon-se
SRC_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_PUB="$SRC_ROOT/public"

# public_html sejajar dengan mon-se. Bisa di-override: DEST=/jalur bash deploy.sh
DEST="${DEST:-$(dirname "$SRC_ROOT")/public_html}"

if [[ ! -d "$SRC_PUB" ]]; then
  echo "✗ Folder sumber tidak ada: $SRC_PUB" >&2
  exit 1
fi
mkdir -p "$DEST"

echo "→ Sumber : $SRC_PUB"
echo "→ Tujuan : $DEST"

# 1) Bersihkan sisa file app lama di public_html (mis. dari salin manual yang
#    keliru: lib/, data/, public/, README.md, server.sh, default.php).
#    File milik hosting TIDAK disentuh (.htaccess, cgi-bin, .well-known, dll).
echo "→ Membersihkan file app lama di public_html…"
rm -rf "$DEST/lib" "$DEST/data" "$DEST/public"
rm -f  "$DEST/README.md" "$DEST/server.sh" "$DEST/deploy.sh" "$DEST/default.php"
rm -f  "$DEST/index.php" "$DEST/input.php" "$DEST/export.php" "$DEST/petugas.php"
rm -rf "$DEST/assets"

# 2) Salin isi public/ (termasuk assets/) ke public_html/.
if command -v rsync >/dev/null 2>&1; then
  rsync -a "$SRC_PUB"/ "$DEST"/
else
  cp -R "$SRC_PUB"/. "$DEST"/
fi

# 3) Tulis ulang path require pada SALINAN PHP di public_html:
#      '/../lib/  ->  '/../mon-se/lib/   (idempoten)
while IFS= read -r -d '' f; do
  grep -q "/\.\./mon-se/lib/" "$f" && continue
  sed -i.bak "s#/\.\./lib/#/../mon-se/lib/#g" "$f" && rm -f "$f.bak"
done < <(find "$DEST" -maxdepth 1 -name "*.php" -print0)

echo "✓ Selesai. Akses: https://mon-se.bps9702.com/"
echo "  lib/ & data/ tetap privat di $SRC_ROOT"
