<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $fresh = !file_exists(DB_PATH);

    // DB belum ada → bangun dari seed.sqlite (master wilayah + prelist + skema).
    // seed.sqlite di-track git; monitoring.sqlite tidak. Ini membuat server baru
    // langsung punya data wilayah tanpa bergantung pada JSON sumber (yang mungkin
    // tidak ada). Bila seed juga tak ada, fallback ke import JSON di bawah.
    if ($fresh && defined('SEED_PATH') && is_file(SEED_PATH)) {
        @copy(SEED_PATH, DB_PATH);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    init_schema($pdo);
    migrate_schema($pdo);
    // Import dari JSON HANYA bila wilayah kosong DAN file sumber tersedia.
    // (Pada deploy dari seed, wilayah sudah terisi sehingga blok ini dilewati.)
    if ((int) $pdo->query('SELECT COUNT(*) FROM wilayah')->fetchColumn() === 0
        && is_file(JSON_PATH)) {
        import_wilayah($pdo);
    }
    // Seed prelist dari muatan saat tabel progres masih kosong DAN file muatan ada.
    if ((int) $pdo->query('SELECT COUNT(*) FROM progres')->fetchColumn() === 0
        && is_file(MUATAN_PATH)) {
        seed_prelist($pdo);
    }
    // Seed pemetaan Tim per kecamatan (idempoten: upsert dari tim_kec.json)
    seed_tim($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void
{
    // Master wilayah + petugas (1 baris = 1 SLS yang menjadi tugas seorang pencacah)
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS wilayah (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            kdprov        TEXT NOT NULL,
            kdkab         TEXT NOT NULL,
            kdkec         TEXT NOT NULL,
            kddesa        TEXT NOT NULL,
            kdsls         TEXT NOT NULL,
            kdsubsls      TEXT NOT NULL,
            nmkab         TEXT NOT NULL,
            nmkec         TEXT NOT NULL,
            nmdesa        TEXT NOT NULL,
            nmsls         TEXT NOT NULL,
            nama_pencacah TEXT NOT NULL,
            email_pencacah TEXT,
            nama_pengawas TEXT,
            kode_sls      TEXT NOT NULL,
            kode14        TEXT NOT NULL DEFAULT '',
            UNIQUE(kdkab, kdkec, kddesa, kdsls, kdsubsls, nama_pencacah)
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wil_petugas ON wilayah(kdkab, nama_pencacah)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wil_kode14 ON wilayah(kode14)');

    // Pemetaan Tim per kecamatan (1 baris per kecamatan). Kunci = kdkab||kdkec (5 digit).
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS tim_kec (
            kode5 TEXT PRIMARY KEY,
            tim   TEXT NOT NULL DEFAULT ''
        )
    SQL);

    // Progres: 1 baris per wilayah (di-overwrite tiap update), dengan jejak waktu
    $cols = [];
    foreach (array_keys(PROGRES_COLS) as $c) {
        $cols[] = "$c INTEGER NOT NULL DEFAULT 0";
    }
    $colsSql = implode(",\n            ", $cols);
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS progres (
            wilayah_id INTEGER PRIMARY KEY REFERENCES wilayah(id) ON DELETE CASCADE,
            $colsSql,
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    SQL);

    // Snapshot harian per wilayah untuk grafik timeseries (1 baris per wilayah per tanggal).
    // Disimpan ulang di hari yang sama → menimpa (UNIQUE wilayah_id + snap_date).
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS progres_hist (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            wilayah_id INTEGER NOT NULL REFERENCES wilayah(id) ON DELETE CASCADE,
            snap_date  TEXT NOT NULL,
            $colsSql,
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(wilayah_id, snap_date)
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hist_date ON progres_hist(snap_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_hist_wil ON progres_hist(wilayah_id)');

    // Catatan terkini per petugas (1 baris per kabupaten + nama pencacah, dapat diedit).
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS catatan (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            kdkab         TEXT NOT NULL,
            nama_pencacah TEXT NOT NULL,
            kendala       TEXT NOT NULL DEFAULT '[]',
            kendala_lain  TEXT NOT NULL DEFAULT '',
            rtl           TEXT NOT NULL DEFAULT '',
            catatan       TEXT NOT NULL DEFAULT '',
            updated_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(kdkab, nama_pencacah)
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_catatan_petugas ON catatan(kdkab, nama_pencacah)');

    // Riwayat setiap penyimpanan catatan (append-only) dengan timestamp.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS catatan_hist (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            kdkab         TEXT NOT NULL,
            nama_pencacah TEXT NOT NULL,
            kendala       TEXT NOT NULL DEFAULT '[]',
            kendala_lain  TEXT NOT NULL DEFAULT '',
            rtl           TEXT NOT NULL DEFAULT '',
            catatan       TEXT NOT NULL DEFAULT '',
            created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_catatanhist_petugas ON catatan_hist(kdkab, nama_pencacah, created_at)');

    // Foto plang sebagai bukti petugas sudah sampai di lokasi tugas.
    // 1 baris = 1 foto. File fisik disimpan di data/uploads/ (privat), kolom
    // `file` hanya menyimpan nama berkasnya. Maks FOTO_MAX_PER_PETUGAS per petugas
    // ditegakkan di lapisan aplikasi (lihat simpan_foto_plang()).
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS foto_plang (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            kdkab         TEXT NOT NULL,
            nama_pencacah TEXT NOT NULL,
            file          TEXT NOT NULL,
            w             INTEGER NOT NULL DEFAULT 0,
            h             INTEGER NOT NULL DEFAULT 0,
            created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_foto_petugas ON foto_plang(kdkab, nama_pencacah)');
}

/**
 * Tambahkan kolom progres baru pada DB lama yang skemanya dibuat sebelum kolom
 * tersebut ada (mis. reject, kunjungan_ulang). Aman dijalankan berulang.
 */
function migrate_schema(PDO $pdo): void
{
    foreach (['progres', 'progres_hist'] as $tbl) {
        $existing = [];
        foreach ($pdo->query("PRAGMA table_info($tbl)") as $col) {
            $existing[$col['name']] = true;
        }
        foreach (array_keys(PROGRES_COLS) as $c) {
            if (!isset($existing[$c])) {
                $pdo->exec("ALTER TABLE $tbl ADD COLUMN $c INTEGER NOT NULL DEFAULT 0");
            }
        }
    }
}

function import_wilayah(PDO $pdo): void
{
    $raw = file_get_contents(JSON_PATH);
    $rows = json_decode($raw, true);
    if (!is_array($rows)) {
        return;
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(<<<SQL
        INSERT OR IGNORE INTO wilayah
            (kdprov,kdkab,kdkec,kddesa,kdsls,kdsubsls,nmkab,nmkec,nmdesa,nmsls,
             nama_pencacah,email_pencacah,nama_pengawas,kode_sls,kode14)
        VALUES
            (:kdprov,:kdkab,:kdkec,:kddesa,:kdsls,:kdsubsls,:nmkab,:nmkec,:nmdesa,:nmsls,
             :nama_pencacah,:email_pencacah,:nama_pengawas,:kode_sls,:kode14)
    SQL);
    foreach ($rows as $r) {
        $kode = ($r['kdprov'] ?? '') . ($r['kdkab'] ?? '') . ($r['kdkec'] ?? '')
              . ($r['kddesa'] ?? '') . ($r['kdsls'] ?? '') . ($r['kdsubsls'] ?? '');
        // Kunci join 14 digit (tanpa prov) — andal walau kdprov sumber bermasalah
        $kode14 = str_pad((string) ($r['kdkab'] ?? ''), 2, '0', STR_PAD_LEFT)
                . str_pad((string) ($r['kdkec'] ?? ''), 3, '0', STR_PAD_LEFT)
                . str_pad((string) ($r['kddesa'] ?? ''), 3, '0', STR_PAD_LEFT)
                . str_pad((string) ($r['kdsls'] ?? ''), 4, '0', STR_PAD_LEFT)
                . str_pad((string) ($r['kdsubsls'] ?? ''), 2, '0', STR_PAD_LEFT);
        $stmt->execute([
            ':kdprov'         => $r['kdprov'] ?? '',
            ':kdkab'          => $r['kdkab'] ?? '',
            ':kdkec'          => $r['kdkec'] ?? '',
            ':kddesa'         => $r['kddesa'] ?? '',
            ':kdsls'          => $r['kdsls'] ?? '',
            ':kdsubsls'       => $r['kdsubsls'] ?? '',
            ':nmkab'          => $r['nmkab'] ?? '',
            ':nmkec'          => $r['nmkec'] ?? '',
            ':nmdesa'         => $r['nmdesa'] ?? '',
            ':nmsls'          => $r['nmsls'] ?? '',
            ':nama_pencacah'  => trim((string) ($r['nama_PENCACAH'] ?? '')),
            ':email_pencacah' => $r['email_PENCACAH'] ?? '',
            ':nama_pengawas'  => trim((string) ($r['nama_PENGAWAS'] ?? '')),
            ':kode_sls'       => $kode,
            ':kode14'         => $kode14,
        ]);
    }
    $pdo->commit();
}

/**
 * Seed kolom prelist pada tabel progres dari muatan_per_wilayah.json.
 * Dicocokkan via kode14 (14 digit terakhir). Hanya mengisi baris progres yang
 * BELUM ada (INSERT OR IGNORE) sehingga input petugas tidak tertimpa.
 */
function seed_prelist(PDO $pdo): int
{
    if (!file_exists(MUATAN_PATH)) {
        return 0;
    }
    $rows = json_decode((string) file_get_contents(MUATAN_PATH), true);
    if (!is_array($rows)) {
        return 0;
    }
    $find = $pdo->prepare('SELECT id FROM wilayah WHERE kode14 = :k');
    $ins  = $pdo->prepare(
        "INSERT INTO progres (wilayah_id, prelist, updated_at)
         VALUES (:wid, :pre, datetime('now','localtime'))
         ON CONFLICT(wilayah_id) DO NOTHING"
    );
    $pdo->beginTransaction();
    $n = 0;
    foreach ($rows as $r) {
        $kode = (string) ($r['kode'] ?? '');
        if (strlen($kode) < 14) {
            continue;
        }
        $kode14 = substr($kode, -14); // buang 2 digit prov di depan
        $find->execute([':k' => $kode14]);
        $wid = $find->fetchColumn();
        if ($wid === false) {
            continue; // baris agregat (mis. prov/kab tanpa SLS) — diabaikan
        }
        $ins->execute([':wid' => (int) $wid, ':pre' => max(0, (int) ($r['muatan'] ?? 0))]);
        if ($ins->rowCount() > 0) {
            $n++;
        }
    }
    $pdo->commit();
    return $n;
}

/**
 * Seed/refresh pemetaan Tim per kecamatan dari data/tim_kec.json.
 * Idempoten (upsert) — aman dijalankan tiap request; perubahan di JSON
 * langsung tersinkron ke DB.
 */
function seed_tim(PDO $pdo): int
{
    if (!file_exists(TIM_PATH)) {
        return 0;
    }
    $map = json_decode((string) file_get_contents(TIM_PATH), true);
    if (!is_array($map)) {
        return 0;
    }
    $ins = $pdo->prepare(
        'INSERT INTO tim_kec (kode5, tim) VALUES (:k, :t)
         ON CONFLICT(kode5) DO UPDATE SET tim = excluded.tim'
    );
    $pdo->beginTransaction();
    $n = 0;
    foreach ($map as $kode5 => $tim) {
        if (!is_string($kode5) || $kode5 === '' || $kode5[0] === '_') {
            continue; // lewati kunci komentar (_comment) / non-string
        }
        $ins->execute([':k' => $kode5, ':t' => (string) $tim]);
        $n++;
    }
    $pdo->commit();
    return $n;
}
