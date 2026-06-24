#!/usr/bin/env bash
#
# deploy.sh — Salin isi mon-se/public ke public_html agar bisa diakses publik.
#
# Struktur hosting (public_html & mon-se SEJAJAR di home):
#   ~/
#   ├── public_html/      <- document root (https://mon-se.bps9702.com/)
#   └── mon-se/           <- repo ini
#       ├── lib/          <- TIDAK disalin (tetap di luar web root, lebih aman)
#       ├── data/         <- TIDAK disalin (DB & PIN tetap privat)
#       └── public/       <- isinya disalin ke public_html/
#
# File PHP di public memanggil  __DIR__ . '/../lib/helpers.php'.
# Setelah berada di public_html/, '../lib' akan keliru menunjuk ke ~/lib,
# jadi skrip ini menulis ulang path tersebut menjadi '/../mon-se/lib/'
# ( public_html/../mon-se/lib  =  ~/mon-se/lib  ✔ ) pada SALINANNYA saja —
# berkas sumber di repo tidak diubah.
#
# Jalankan dari dalam folder mon-se (via SSH / cPanel Terminal):
#   bash deploy.sh
#
set -euo pipefail

# Lokasi skrip = root repo mon-se
SRC_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_PUB="$SRC_ROOT/public"

# Tentukan public_html (sejajar dengan mon-se). Bisa di-override:
#   DEST=/jalur/lain bash deploy.sh
DEST="${DEST:-$(dirname "$SRC_ROOT")/public_html}"

if [[ ! -d "$SRC_PUB" ]]; then
  echo "✗ Folder sumber tidak ada: $SRC_PUB" >&2
  exit 1
fi
mkdir -p "$DEST"

echo "→ Sumber : $SRC_PUB"
echo "→ Tujuan : $DEST"

# Salin isi public/ (termasuk assets/) ke public_html/.
# rsync bila tersedia (hapus file usang via --delete-after, tapi JANGAN sentuh
# berkas non-app di public_html mis. .well-known, cgi-bin).
if command -v rsync >/dev/null 2>&1; then
  rsync -a "$SRC_PUB"/ "$DEST"/
else
  cp -R "$SRC_PUB"/. "$DEST"/
fi

# Tulis ulang path require pada SALINAN PHP di public_html:
#   '/../lib/  ->  '/../mon-se/lib/
# Idempoten: kalau sudah '/../mon-se/lib/' tidak akan dobel.
while IFS= read -r -d '' f; do
  # lewati yang sudah benar
  if grep -q "/\.\./mon-se/lib/" "$f"; then
    continue
  fi
  sed -i.bak "s#/\.\./lib/#/../mon-se/lib/#g" "$f" && rm -f "$f.bak"
done < <(find "$DEST" -maxdepth 1 -name "*.php" -print0)

echo "✓ Selesai. Aplikasi siap diakses di document root (mis. https://mon-se.bps9702.com/)."
echo "  lib/ & data/ tetap di $SRC_ROOT (di luar web root)."
