<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function e(int|string|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function nf(int|float $n): string
{
    return number_format((float) $n, 0, ',', '.');
}

/** Daftar kabupaten (kode => nama). */
function list_kab(): array
{
    $out = [];
    foreach (db()->query('SELECT DISTINCT kdkab, nmkab FROM wilayah ORDER BY nmkab') as $r) {
        $out[$r['kdkab']] = $r['nmkab'];
    }
    return $out;
}

/** Daftar pencacah pada satu kabupaten beserta jumlah SLS tugasnya. */
function list_pencacah(string $kdkab): array
{
    $stmt = db()->prepare(
        'SELECT nama_pencacah, COUNT(*) AS jml
         FROM wilayah WHERE kdkab = :k AND nama_pencacah <> ""
         GROUP BY nama_pencacah ORDER BY nama_pencacah'
    );
    $stmt->execute([':k' => $kdkab]);
    return $stmt->fetchAll();
}

/**
 * Daftar pencacah pada satu kabupaten beserta kecamatan & desa tempat tugasnya.
 * Untuk filter berjenjang (kecamatan → desa → petugas) di sisi klien.
 *
 * @return array Tiap baris: [
 *   'nama' => string, 'jml' => int (jumlah SLS),
 *   'kec'  => [['kode' => kdkec, 'nama' => nmkec], ...],   // kecamatan unik petugas
 *   'desa' => [['kec' => kdkec, 'kode' => kddesa, 'nama' => nmdesa], ...] // desa unik
 * ]
 */
function pencacah_berjenjang(string $kdkab): array
{
    $stmt = db()->prepare(
        'SELECT nama_pencacah, kdkec, nmkec, kddesa, nmdesa
         FROM wilayah
         WHERE kdkab = :k AND nama_pencacah <> ""
         ORDER BY nama_pencacah, nmkec, nmdesa'
    );
    $stmt->execute([':k' => $kdkab]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $nm = $r['nama_pencacah'];
        if (!isset($out[$nm])) {
            $out[$nm] = ['nama' => $nm, 'jml' => 0, 'kec' => [], 'desa' => []];
        }
        $out[$nm]['jml']++;
        $kecKey = $r['kdkec'];
        $out[$nm]['kec'][$kecKey]  = ['kode' => $r['kdkec'], 'nama' => $r['nmkec']];
        $desaKey = $r['kdkec'] . '|' . $r['kddesa'];
        $out[$nm]['desa'][$desaKey] = ['kec' => $r['kdkec'], 'kode' => $r['kddesa'], 'nama' => $r['nmdesa']];
    }

    // Buang kunci asosiatif agar rapi untuk JSON (array index).
    return array_map(function ($p) {
        $p['kec']  = array_values($p['kec']);
        $p['desa'] = array_values($p['desa']);
        return $p;
    }, array_values($out));
}

/** Semua SLS tugas seorang pencacah di satu kabupaten, beserta progresnya. */
function wilayah_petugas(string $kdkab, string $nama): array
{
    $cols = implode(',', array_map(fn($c) => "p.$c", array_keys(PROGRES_COLS)));
    $stmt = db()->prepare(
        "SELECT w.*, $cols, p.updated_at
         FROM wilayah w
         LEFT JOIN progres p ON p.wilayah_id = w.id
         WHERE w.kdkab = :k AND w.nama_pencacah = :n
         ORDER BY w.kdkec, w.kddesa, w.kdsls, w.kdsubsls"
    );
    $stmt->execute([':k' => $kdkab, ':n' => $nama]);
    return $stmt->fetchAll();
}

/**
 * Rekap progres bertingkat (drill-down).
 * $level: 'kab' (semua kabupaten) | 'kec' (kecamatan dlm 1 kab) | 'desa' (desa dlm 1 kec).
 * Mengembalikan baris berisi: code, name, child_count, jml kolom progres, dan kunci drill.
 */
function rekap(string $level, array $f = []): array
{
    $sums = [];
    foreach (array_keys(PROGRES_COLS) as $c) {
        $sums[] = "COALESCE(SUM(p.$c),0) AS $c";
    }
    $sumsSql = implode(",\n", $sums);

    $where = '1=1';
    $params = [];
    if (!empty($f['kab'])) { $where .= ' AND w.kdkab = :kab'; $params[':kab'] = $f['kab']; }
    if (!empty($f['kec'])) { $where .= ' AND w.kdkec = :kec'; $params[':kec'] = $f['kec']; }

    switch ($level) {
        case 'kec':
            $sel = "w.kdkab AS kab, w.kdkec AS code, w.nmkec AS name, COALESCE(t.tim,'') AS tim";
            $grp = 'w.kdkab, w.kdkec, w.nmkec';
            $childCnt = "COUNT(DISTINCT w.kddesa) AS child_count";
            $codeDisp = "w.kdkab || w.kdkec";
            $ord = 'w.kdkec';
            break;
        case 'desa':
            $sel = 'w.kdkab AS kab, w.kdkec AS kec, w.kddesa AS code, w.nmdesa AS name';
            $grp = 'w.kdkab, w.kdkec, w.kddesa, w.nmdesa';
            $childCnt = "COUNT(*) AS child_count";
            $codeDisp = "w.kdkab || w.kdkec || w.kddesa";
            $ord = 'w.kddesa';
            break;
        case 'kab':
        default:
            $sel = 'w.kdkab AS code, w.nmkab AS name';
            $grp = 'w.kdkab, w.nmkab';
            $childCnt = "COUNT(DISTINCT w.kdkec) AS child_count";
            $codeDisp = "w.kdprov || w.kdkab";
            $ord = 'w.nmkab';
            break;
    }

    $stmt = db()->prepare(
        "SELECT $sel, $codeDisp AS code_disp, $childCnt, $sumsSql
         FROM wilayah w
         LEFT JOIN progres p ON p.wilayah_id = w.id
         LEFT JOIN tim_kec t ON t.kode5 = w.kdkab || w.kdkec
         WHERE $where
         GROUP BY $grp
         ORDER BY $ord"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Rekap progres diagregasi per Tim (lintas kecamatan).
 * Menjumlahkan seluruh wilayah pada scope kabupaten yang dipilih, dikelompokkan
 * berdasarkan Tim (tim_kec). Kecamatan tanpa Tim dikelompokkan sebagai "Tanpa Tim".
 *
 * @param array $f Filter; gunakan ['kab' => kode] untuk membatasi ke satu kabupaten.
 * @return array Baris berisi: tim (label), kec_count, dan jml tiap kolom progres.
 */
function rekap_tim(array $f = []): array
{
    $sums = [];
    foreach (array_keys(PROGRES_COLS) as $c) {
        $sums[] = "COALESCE(SUM(p.$c),0) AS $c";
    }
    $sumsSql = implode(",\n", $sums);

    $where = '1=1';
    $params = [];
    if (!empty($f['kab'])) { $where .= ' AND w.kdkab = :kab'; $params[':kab'] = $f['kab']; }
    if (!empty($f['kec'])) { $where .= ' AND w.kdkec = :kec'; $params[':kec'] = $f['kec']; }

    // Kelompokkan per Tim; kecamatan tanpa pemetaan → "Tanpa Tim".
    $stmt = db()->prepare(
        "SELECT COALESCE(NULLIF(t.tim,''), 'Tanpa Tim') AS tim,
                COUNT(DISTINCT w.kdkab || w.kdkec) AS kec_count,
                $sumsSql
         FROM wilayah w
         LEFT JOIN progres p ON p.wilayah_id = w.id
         LEFT JOIN tim_kec t ON t.kode5 = w.kdkab || w.kdkec
         WHERE $where
         GROUP BY COALESCE(NULLIF(t.tim,''), 'Tanpa Tim')
         ORDER BY tim"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Simpan progres satu wilayah.
 *
 * @param string $mode 'kumulatif' (default) = semua nilai menimpa nilai lama.
 *                      'harian'              = nilai ditambahkan ke nilai sebelumnya,
 *                                              kecuali kolom ALWAYS_CUMULATIVE_COLS
 *                                              (prelist, reject, kunjungan_ulang) yang tetap menimpa.
 */
function simpan_progres(int $wilayahId, array $vals, string $mode = 'kumulatif'): void
{
    $keys = array_keys(PROGRES_COLS);
    $setCols = implode(',', $keys);
    $placeholders = implode(',', array_map(fn($c) => ":$c", $keys));

    // Pada mode harian, kolom non-always-cumulative ditambahkan ke nilai existing.
    $additive = $mode === 'harian';
    $updates = implode(',', array_map(function ($c) use ($additive) {
        if ($additive && !in_array($c, ALWAYS_CUMULATIVE_COLS, true)) {
            return "$c = $c + excluded.$c";
        }
        return "$c = excluded.$c";
    }, $keys));

    $params = [':wid' => $wilayahId];
    foreach ($keys as $c) {
        $params[":$c"] = max(0, (int) ($vals[$c] ?? 0));
    }

    $sql = "INSERT INTO progres (wilayah_id, $setCols, updated_at)
            VALUES (:wid, $placeholders, datetime('now','localtime'))
            ON CONFLICT(wilayah_id) DO UPDATE SET $updates, updated_at = datetime('now','localtime')";
    db()->prepare($sql)->execute($params);

    // Snapshot harian untuk grafik timeseries: selalu cerminkan nilai kumulatif terkini.
    // Ambil nilai progres terbaru (sudah termasuk penambahan di atas) lalu simpan apa adanya.
    $cur = db()->prepare('SELECT ' . $setCols . ' FROM progres WHERE wilayah_id = :wid');
    $cur->execute([':wid' => $wilayahId]);
    $curRow = $cur->fetch() ?: [];

    $histParams = [':wid' => $wilayahId];
    foreach ($keys as $c) {
        $histParams[":$c"] = max(0, (int) ($curRow[$c] ?? 0));
    }

    $histUpdates = implode(',', array_map(fn($c) => "$c = excluded.$c", $keys));
    $histSql = "INSERT INTO progres_hist (wilayah_id, snap_date, $setCols, updated_at)
                VALUES (:wid, date('now','localtime'), $placeholders, datetime('now','localtime'))
                ON CONFLICT(wilayah_id, snap_date) DO UPDATE SET $histUpdates, updated_at = datetime('now','localtime')";
    db()->prepare($histSql)->execute($histParams);

    // Catatan: pruning dimatikan agar SEMUA tanggal snapshot tersimpan permanen
    // (mendukung switch "Semua / 5 tanggal" pada grafik). Fungsi prune_hist()
    // tetap ada bila sewaktu-waktu ingin diaktifkan kembali.
}

/** Hapus snapshot tanggal lama, sisakan HIST_MAX tanggal terbaru untuk satu wilayah. */
function prune_hist(int $wilayahId): void
{
    $stmt = db()->prepare(
        'DELETE FROM progres_hist
         WHERE wilayah_id = :w AND snap_date NOT IN (
             SELECT snap_date FROM progres_hist
             WHERE wilayah_id = :w2 ORDER BY snap_date DESC LIMIT :lim
         )'
    );
    $stmt->bindValue(':w', $wilayahId, PDO::PARAM_INT);
    $stmt->bindValue(':w2', $wilayahId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', HIST_MAX, PDO::PARAM_INT);
    $stmt->execute();
}

/**
 * Tren agregat per tanggal snapshot untuk grafik timeseries dashboard.
 * Menjumlahkan seluruh wilayah pada scope (kab/kec) per snap_date.
 *
 * @param int|null $limit Jumlah tanggal terakhir yang diambil; null = semua tanggal.
 * @return array ['dates' => [...], 'series' => [colKey => [nilai per tanggal]]] (urut menaik)
 */
function rekap_timeseries(array $f = [], ?int $limit = null): array
{
    $cols = array_keys(TIMESERIES_COLS);
    $sums = implode(",\n", array_map(fn($c) => "COALESCE(SUM(h.$c),0) AS $c", $cols));

    $where = '1=1';
    $params = [];
    if (!empty($f['kab'])) { $where .= ' AND w.kdkab = :kab'; $params[':kab'] = $f['kab']; }
    if (!empty($f['kec'])) { $where .= ' AND w.kdkec = :kec'; $params[':kec'] = $f['kec']; }

    $limitSql = $limit !== null ? ' LIMIT ' . (int) $limit : '';
    $stmt = db()->prepare(
        "SELECT h.snap_date AS d, $sums
         FROM progres_hist h
         JOIN wilayah w ON w.id = h.wilayah_id
         WHERE $where
         GROUP BY h.snap_date
         ORDER BY h.snap_date DESC" . $limitSql
    );
    $stmt->execute($params);
    $rows = array_reverse($stmt->fetchAll()); // urut menaik (lama → baru)

    $dates  = [];
    $series = array_fill_keys($cols, []);
    foreach ($rows as $r) {
        $dates[] = $r['d'];
        foreach ($cols as $c) {
            $series[$c][] = (int) $r[$c];
        }
    }
    return ['dates' => $dates, 'series' => $series];
}

/**
 * Tren harian untuk SATU petugas (agregat seluruh wilayah tugasnya per snap_date).
 * Mengembalikan ['dates' => [...], 'series' => [colKey => [nilai per tanggal]]]
 * dengan kolom dari TIMESERIES_COLS (urut menaik).
 *
 * @param int|null $limit Jumlah tanggal terakhir yang diambil; null = semua tanggal.
 */
function rekap_timeseries_petugas(string $kdkab, string $nama, ?int $limit = null): array
{
    $cols = array_keys(TIMESERIES_COLS);
    $sums = implode(",\n", array_map(fn($c) => "COALESCE(SUM(h.$c),0) AS $c", $cols));

    $limitSql = $limit !== null ? ' LIMIT ' . (int) $limit : '';
    $stmt = db()->prepare(
        "SELECT h.snap_date AS d, $sums
         FROM progres_hist h
         JOIN wilayah w ON w.id = h.wilayah_id
         WHERE w.kdkab = :k AND w.nama_pencacah = :n
         GROUP BY h.snap_date
         ORDER BY h.snap_date DESC" . $limitSql
    );
    $stmt->execute([':k' => $kdkab, ':n' => $nama]);
    $rows = array_reverse($stmt->fetchAll());

    $dates  = [];
    $series = array_fill_keys($cols, []);
    foreach ($rows as $r) {
        $dates[] = $r['d'];
        foreach ($cols as $c) {
            $series[$c][] = (int) $r[$c];
        }
    }
    return ['dates' => $dates, 'series' => $series];
}

function layout_head(string $title, bool $withChart = false): string
{
    $t = e($title);
    $chart = $withChart
        ? '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>'
        : '';
    return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$t · Monitoring SE</title>
<script>
// Terapkan tema tersimpan sebelum render untuk mencegah kedip
(function(){var t=localStorage.getItem('theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();
</script>
<link rel="stylesheet" href="/assets/app.css">
$chart
</head>
<body>
HTML;
}

/** Tombol toggle tema untuk diletakkan di topbar. */
function theme_toggle(): string
{
    return <<<HTML
<button class="theme-toggle" type="button" onclick="toggleTheme()" title="Ganti tema" aria-label="Ganti tema">
  <span class="ic-dark">🌙</span><span class="ic-light">☀️</span>
</button>
<script>
function toggleTheme(){
  var cur=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
  document.documentElement.setAttribute('data-theme',cur);
  localStorage.setItem('theme',cur);
  document.dispatchEvent(new CustomEvent('themechange',{detail:cur}));
}
</script>
HTML;
}

/* ============================================================
 * Catatan Petugas (kendala, rencana tindak lanjut, catatan)
 * ============================================================ */

/** Ambil catatan terkini seorang petugas. Mengembalikan array atau null. */
function get_catatan(string $kdkab, string $nama): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM catatan WHERE kdkab = :k AND nama_pencacah = :n'
    );
    $stmt->execute([':k' => $kdkab, ':n' => $nama]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Riwayat catatan seorang petugas, terbaru di atas. */
function catatan_history(string $kdkab, string $nama, int $limit = 50): array
{
    $stmt = db()->prepare(
        'SELECT * FROM catatan_hist
         WHERE kdkab = :k AND nama_pencacah = :n
         ORDER BY created_at DESC, id DESC LIMIT :lim'
    );
    $stmt->bindValue(':k', $kdkab);
    $stmt->bindValue(':n', $nama);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Hapus satu entri riwayat catatan milik petugas tertentu.
 * Di-scope ke kdkab + nama agar tidak bisa menghapus milik petugas lain.
 *
 * @return bool true bila ada baris terhapus.
 */
function hapus_catatan_hist(int $id, string $kdkab, string $nama): bool
{
    $stmt = db()->prepare(
        'DELETE FROM catatan_hist WHERE id = :id AND kdkab = :k AND nama_pencacah = :n'
    );
    $stmt->execute([':id' => $id, ':k' => $kdkab, ':n' => $nama]);
    return $stmt->rowCount() > 0;
}

/**
 * Simpan catatan petugas: timpa baris terkini (catatan) + tambahkan ke riwayat (catatan_hist).
 *
 * @param array  $kendala  daftar kunci kendala terpilih (subset KENDALA_OPTS).
 */
function simpan_catatan(string $kdkab, string $nama, array $kendala, string $kendalaLain, string $rtl, string $catatan): void
{
    // Hanya simpan kunci kendala yang valid.
    $kendala = array_values(array_filter(
        $kendala,
        fn($k) => array_key_exists($k, KENDALA_OPTS)
    ));
    $kendalaJson = json_encode($kendala, JSON_UNESCAPED_UNICODE);
    $kendalaLain = in_array('lainnya', $kendala, true) ? trim($kendalaLain) : '';

    $pdo = db();

    // Upsert catatan terkini
    $up = $pdo->prepare(
        "INSERT INTO catatan (kdkab, nama_pencacah, kendala, kendala_lain, rtl, catatan, updated_at)
         VALUES (:k, :n, :kd, :kl, :rtl, :cat, datetime('now','localtime'))
         ON CONFLICT(kdkab, nama_pencacah) DO UPDATE SET
             kendala = excluded.kendala,
             kendala_lain = excluded.kendala_lain,
             rtl = excluded.rtl,
             catatan = excluded.catatan,
             updated_at = datetime('now','localtime')"
    );
    $up->execute([
        ':k' => $kdkab, ':n' => $nama, ':kd' => $kendalaJson,
        ':kl' => $kendalaLain, ':rtl' => $rtl, ':cat' => $catatan,
    ]);

    // Append riwayat
    $hist = $pdo->prepare(
        "INSERT INTO catatan_hist (kdkab, nama_pencacah, kendala, kendala_lain, rtl, catatan, created_at)
         VALUES (:k, :n, :kd, :kl, :rtl, :cat, datetime('now','localtime'))"
    );
    $hist->execute([
        ':k' => $kdkab, ':n' => $nama, ':kd' => $kendalaJson,
        ':kl' => $kendalaLain, ':rtl' => $rtl, ':cat' => $catatan,
    ]);
}

/**
 * Rekap riwayat catatan SEMUA petugas, dikelompokkan per tanggal (terbaru di atas).
 * Setiap entri pada catatan_hist menjadi satu baris; dikelompokkan berdasarkan
 * tanggal (bagian date dari created_at).
 *
 * @param array $f Filter opsional: ['kab' => kode] untuk membatasi ke satu kabupaten,
 *                 ['tgl' => 'YYYY-MM-DD'] untuk membatasi ke satu tanggal.
 * @return array Daftar grup: [['tanggal' => 'YYYY-MM-DD', 'items' => [baris catatan_hist + nmkab]], ...]
 *               urut tanggal menurun, item dalam grup urut waktu menurun.
 */
function rekap_catatan(array $f = []): array
{
    $where = "1=1";
    $params = [];
    if (!empty($f['kab'])) { $where .= ' AND ch.kdkab = :kab'; $params[':kab'] = $f['kab']; }
    if (!empty($f['tgl'])) { $where .= " AND date(ch.created_at) = :tgl"; $params[':tgl'] = $f['tgl']; }

    // Nama kabupaten diambil lewat subquery agar tidak bergantung pada keberadaan
    // wilayah tertentu; catatan_hist menyimpan kdkab langsung.
    $stmt = db()->prepare(
        "SELECT ch.*,
                date(ch.created_at) AS tanggal,
                (SELECT nmkab FROM wilayah w WHERE w.kdkab = ch.kdkab LIMIT 1) AS nmkab
         FROM catatan_hist ch
         WHERE $where
         ORDER BY ch.created_at DESC, ch.id DESC"
    );
    $stmt->execute($params);

    $groups = [];
    foreach ($stmt->fetchAll() as $r) {
        $groups[$r['tanggal']][] = $r;
    }

    $out = [];
    foreach ($groups as $tanggal => $items) {
        $out[] = ['tanggal' => $tanggal, 'items' => $items];
    }
    return $out;
}

/** Daftar tanggal (YYYY-MM-DD) yang memiliki riwayat catatan, terbaru di atas. */
function tanggal_catatan(array $f = []): array
{
    $where = "1=1";
    $params = [];
    if (!empty($f['kab'])) { $where .= ' AND kdkab = :kab'; $params[':kab'] = $f['kab']; }

    $stmt = db()->prepare(
        "SELECT DISTINCT date(created_at) AS tanggal
         FROM catatan_hist WHERE $where
         ORDER BY tanggal DESC"
    );
    $stmt->execute($params);
    return array_map(fn($r) => $r['tanggal'], $stmt->fetchAll());
}

/* ============================================================
 * Foto Plang (bukti petugas sudah sampai di lokasi tugas)
 * ============================================================ */

/** Foto plang milik satu petugas, terbaru di atas. */
function foto_petugas(string $kdkab, string $nama): array
{
    $stmt = db()->prepare(
        'SELECT * FROM foto_plang WHERE kdkab = :k AND nama_pencacah = :n
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([':k' => $kdkab, ':n' => $nama]);
    return $stmt->fetchAll();
}

/** Jumlah foto plang milik satu petugas. */
function foto_petugas_count(string $kdkab, string $nama): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM foto_plang WHERE kdkab = :k AND nama_pencacah = :n'
    );
    $stmt->execute([':k' => $kdkab, ':n' => $nama]);
    return (int) $stmt->fetchColumn();
}

/**
 * Semua foto plang (untuk galeri dashboard), terbaru di atas, dengan nama
 * kabupaten. Filter opsional ['kab' => kode].
 */
function foto_semua(array $f = []): array
{
    $where = '1=1';
    $params = [];
    if (!empty($f['kab'])) { $where .= ' AND fp.kdkab = :kab'; $params[':kab'] = $f['kab']; }

    $stmt = db()->prepare(
        "SELECT fp.*,
                (SELECT nmkab FROM wilayah w WHERE w.kdkab = fp.kdkab LIMIT 1) AS nmkab
         FROM foto_plang fp
         WHERE $where
         ORDER BY fp.created_at DESC, fp.id DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Simpan beberapa foto plang unggahan untuk satu petugas.
 * Menerima struktur $_FILES['foto'] (multi-file). Menegakkan:
 *  - batas FOTO_MAX_PER_PETUGAS (total existing + baru),
 *  - tipe pada FOTO_MIME_EXT, ukuran <= FOTO_MAX_BYTES,
 *  - validasi bahwa berkas benar-benar gambar (getimagesize).
 *
 * @return array{saved:int, errors:string[]}
 */
function simpan_foto_plang(string $kdkab, string $nama, array $files): array
{
    $errors = [];
    $saved  = 0;

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
        return ['saved' => 0, 'errors' => ['Folder unggahan tidak dapat dibuat.']];
    }

    // Normalisasi $_FILES multi-file menjadi daftar entri tunggal.
    $items = [];
    if (isset($files['name']) && is_array($files['name'])) {
        foreach ($files['name'] as $i => $n) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue; // slot kosong
            }
            $items[] = [
                'name'     => $n,
                'type'     => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $files['size'][$i] ?? 0,
            ];
        }
    }

    if (!$items) {
        return ['saved' => 0, 'errors' => ['Tidak ada foto yang dipilih.']];
    }

    $sisa = FOTO_MAX_PER_PETUGAS - foto_petugas_count($kdkab, $nama);
    if ($sisa <= 0) {
        return ['saved' => 0, 'errors' => ['Batas ' . FOTO_MAX_PER_PETUGAS . ' foto sudah tercapai. Hapus dahulu untuk mengganti.']];
    }

    $ins = db()->prepare(
        "INSERT INTO foto_plang (kdkab, nama_pencacah, file, w, h, created_at)
         VALUES (:k, :n, :f, :w, :h, datetime('now','localtime'))"
    );

    foreach ($items as $idx => $it) {
        if ($saved >= $sisa) {
            $errors[] = 'Sebagian foto tidak disimpan: melebihi batas ' . FOTO_MAX_PER_PETUGAS . ' foto.';
            break;
        }
        $label = 'Foto #' . ($idx + 1);
        if ($it['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($it['tmp_name'])) {
            $errors[] = "$label gagal diunggah.";
            continue;
        }
        if ($it['size'] > FOTO_MAX_BYTES) {
            $errors[] = "$label melebihi " . round(FOTO_MAX_BYTES / 1048576) . ' MB.';
            continue;
        }
        $info = @getimagesize($it['tmp_name']);
        if ($info === false || !isset(FOTO_MIME_EXT[$info['mime']])) {
            $errors[] = "$label bukan gambar yang didukung (JPG/PNG/WebP).";
            continue;
        }
        $ext  = FOTO_MIME_EXT[$info['mime']];
        $fn   = 'plang_' . $kdkab . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = UPLOAD_DIR . '/' . $fn;
        if (!move_uploaded_file($it['tmp_name'], $dest)) {
            $errors[] = "$label gagal disimpan ke server.";
            continue;
        }
        @chmod($dest, 0644);
        $ins->execute([
            ':k' => $kdkab, ':n' => $nama, ':f' => $fn,
            ':w' => (int) $info[0], ':h' => (int) $info[1],
        ]);
        $saved++;
    }

    return ['saved' => $saved, 'errors' => $errors];
}

/**
 * Hapus satu foto plang milik petugas tertentu (scope kdkab+nama) beserta berkasnya.
 * @return bool true bila baris terhapus.
 */
function hapus_foto_plang(int $id, string $kdkab, string $nama): bool
{
    $sel = db()->prepare(
        'SELECT file FROM foto_plang WHERE id = :id AND kdkab = :k AND nama_pencacah = :n'
    );
    $sel->execute([':id' => $id, ':k' => $kdkab, ':n' => $nama]);
    $file = $sel->fetchColumn();
    if ($file === false) {
        return false;
    }
    $del = db()->prepare(
        'DELETE FROM foto_plang WHERE id = :id AND kdkab = :k AND nama_pencacah = :n'
    );
    $del->execute([':id' => $id, ':k' => $kdkab, ':n' => $nama]);
    if ($del->rowCount() > 0) {
        $path = UPLOAD_DIR . '/' . basename((string) $file);
        if (is_file($path)) { @unlink($path); }
        return true;
    }
    return false;
}

/** Render daftar label kendala dari JSON tersimpan (+ teks "Lainnya"). */
function kendala_labels(?string $json, string $lain = ''): string
{
    $keys = json_decode((string) $json, true);
    if (!is_array($keys) || !$keys) {
        return '—';
    }
    $out = [];
    foreach ($keys as $k) {
        if ($k === 'lainnya') {
            $out[] = 'Lainnya' . ($lain !== '' ? ': ' . $lain : '');
        } elseif (isset(KENDALA_OPTS[$k])) {
            $out[] = KENDALA_OPTS[$k];
        }
    }
    return $out ? implode(', ', $out) : '—';
}
