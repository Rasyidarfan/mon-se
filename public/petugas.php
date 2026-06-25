<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/helpers.php';

$kabList = list_kab();
$kab  = $_REQUEST['kab']  ?? '';
$nama = $_REQUEST['nama'] ?? '';

if (!isset($kabList[$kab]) || $nama === '') {
    header('Location: /index.php');
    exit;
}

$flash = null;

// --- Simpan progres (butuh PIN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $pin = trim((string) ($_POST['pin'] ?? ''));
    if (!hash_equals(PETUGAS_PIN, $pin)) {
        $flash = ['err', 'PIN salah. Progres tidak disimpan.'];
    } else {
        $mode = ($_POST['mode'] ?? 'kumulatif') === 'harian' ? 'harian' : 'kumulatif';
        $rows = $_POST['row'] ?? [];
        $n = 0;
        foreach ($rows as $wid => $vals) {
            // Pastikan wilayah benar milik petugas ini
            $chk = db()->prepare('SELECT 1 FROM wilayah WHERE id = :id AND kdkab = :k AND nama_pencacah = :n');
            $chk->execute([':id' => (int) $wid, ':k' => $kab, ':n' => $nama]);
            if ($chk->fetchColumn()) {
                simpan_progres((int) $wid, is_array($vals) ? $vals : [], $mode);
                $n++;
            }
        }
        $modeLbl = $mode === 'harian' ? 'harian (tambah)' : 'kumulatif';
        $flash = ['ok', "Progres tersimpan ($modeLbl) untuk $n wilayah pada " . date('d/m/Y H:i') . "."];
    }
}

// --- Simpan catatan petugas (butuh PIN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_catatan') {
    $pin = trim((string) ($_POST['pin'] ?? ''));
    if (!hash_equals(PETUGAS_PIN, $pin)) {
        $flash = ['err', 'PIN salah. Catatan tidak disimpan.'];
    } else {
        simpan_catatan(
            $kab,
            $nama,
            is_array($_POST['kendala'] ?? null) ? $_POST['kendala'] : [],
            (string) ($_POST['kendala_lain'] ?? ''),
            trim((string) ($_POST['rtl'] ?? '')),
            trim((string) ($_POST['catatan'] ?? ''))
        );
        $flash = ['ok', 'Catatan tersimpan pada ' . date('d/m/Y H:i') . '.'];
    }
}

// --- Hapus satu entri riwayat catatan (butuh PIN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_catatan') {
    $pin = trim((string) ($_POST['pin'] ?? ''));
    if (!hash_equals(PETUGAS_PIN, $pin)) {
        $flash = ['err', 'PIN salah. Catatan tidak dihapus.'];
    } else {
        $ok = hapus_catatan_hist((int) ($_POST['hist_id'] ?? 0), $kab, $nama);
        $flash = $ok
            ? ['ok', 'Satu entri riwayat catatan dihapus.']
            : ['err', 'Entri tidak ditemukan.'];
    }
}

// --- Upload foto plang (butuh PIN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_foto') {
    $pin = trim((string) ($_POST['pin'] ?? ''));
    if (!hash_equals(PETUGAS_PIN, $pin)) {
        $flash = ['err', 'PIN salah. Foto tidak diunggah.'];
    } else {
        $res = simpan_foto_plang($kab, $nama, $_FILES['foto'] ?? []);
        if ($res['saved'] > 0 && !$res['errors']) {
            $flash = ['ok', $res['saved'] . ' foto plang berhasil diunggah.'];
        } elseif ($res['saved'] > 0) {
            $flash = ['ok', $res['saved'] . ' foto diunggah. ' . implode(' ', $res['errors'])];
        } else {
            $flash = ['err', implode(' ', $res['errors']) ?: 'Tidak ada foto yang diunggah.'];
        }
    }
}

// --- Hapus satu foto plang (butuh PIN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_foto') {
    $pin = trim((string) ($_POST['pin'] ?? ''));
    if (!hash_equals(PETUGAS_PIN, $pin)) {
        $flash = ['err', 'PIN salah. Foto tidak dihapus.'];
    } else {
        $ok = hapus_foto_plang((int) ($_POST['foto_id'] ?? 0), $kab, $nama);
        $flash = $ok ? ['ok', 'Foto dihapus.'] : ['err', 'Foto tidak ditemukan.'];
    }
}

$wilayah  = wilayah_petugas($kab, $nama);
$colKeys  = array_keys(PROGRES_COLS);

// Ringkasan total petugas
$tot = array_fill_keys($colKeys, 0);
foreach ($wilayah as $w) {
    foreach ($colKeys as $c) {
        $tot[$c] += (int) ($w[$c] ?? 0);
    }
}
$pengawas = $wilayah[0]['nama_pengawas'] ?? '';
$email    = $wilayah[0]['email_pencacah'] ?? '';

// Catatan petugas + riwayat
$catatan     = get_catatan($kab, $nama);
$catKendala  = $catatan ? (json_decode((string) $catatan['kendala'], true) ?: []) : [];
$catHistory  = catatan_history($kab, $nama);

// Foto plang petugas
$fotoList  = foto_petugas($kab, $nama);
$fotoSisa  = FOTO_MAX_PER_PETUGAS - count($fotoList);

// Grafik harian (tren per tanggal) untuk petugas ini — ambil SEMUA tanggal,
// switch "5 / Semua" memotong di sisi klien.
$ts = rekap_timeseries_petugas($kab, $nama);
$tsLabels = array_map(fn($d) => date('d/m', strtotime($d)), $ts['dates']);
$tsSeries = [];
foreach (TIMESERIES_COLS as $key => $label) {
    $tsSeries[] = ['label' => $label, 'data' => $ts['series'][$key] ?? []];
}
$tsHasData = count($tsLabels) >= 1;

echo layout_head('Form Petugas', true);
?>
<header class="topbar">
  <div class="logo">SE</div>
  <div>
    <h1><?= e(APP_TITLE) ?></h1>
    <div class="sub"><?= e(APP_SUBTITLE) ?></div>
  </div>
  <div class="spacer"></div>
  <a class="btn ghost" href="/input.php?kab=<?= e($kab) ?>">← Ganti Petugas</a>
  <?= theme_toggle() ?>
</header>

<div class="wrap">
  <div class="crumb">
    <a href="/index.php">Dashboard</a>
    <span>›</span>
    <a href="/input.php?kab=<?= e($kab) ?>"><?= e($kabList[$kab]) ?></a>
    <span>›</span>
    <span class="cur"><?= e($nama) ?></span>
  </div>

  <?php if ($flash): ?>
    <div class="flash <?= $flash[0] ?>"><?= e($flash[1]) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2><?= e($nama) ?><?= $email ? ' · ' . e($email) : '' ?></h2>
    <p class="hint">
      Pencacah · <?= count($wilayah) ?> wilayah tugas
      <?= $pengawas ? ' · Pengawas: ' . e($pengawas) : '' ?>
    </p>

    <div class="grid-stats">
      <div class="stat"><div class="v"><?= nf($tot['prelist']) ?></div><div class="k">Prelist</div></div>
      <div class="stat"><div class="v"><?= nf($tot['responden']) ?></div><div class="k">Responden Didata</div></div>
      <div class="stat"><div class="v"><?= nf($tot['ub'] + $tot['um'] + $tot['umk']) ?></div><div class="k">UB+UM+UMK Didata</div></div>
      <div class="stat"><div class="v"><?= nf($tot['usaha_keluarga']) ?></div><div class="k">Usaha dlm Keluarga</div></div>
    </div>

    <div class="mode-switch">
      <label class="mode-opt">
        <input type="radio" name="mode" value="kumulatif" form="frm" checked>
        <span><strong>Kumulatif</strong><br><small class="muted">Nilai menimpa total lama</small></span>
      </label>
      <label class="mode-opt">
        <input type="radio" name="mode" value="harian" form="frm">
        <span><strong>Harian (tambah)</strong><br><small class="muted">Ditambahkan ke nilai sebelumnya. Prelist, Reject &amp; Kunjungan Ulang tetap kumulatif.</small></span>
      </label>
    </div>

    <form method="post" action="/petugas.php" id="frm">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="kab" value="<?= e($kab) ?>">
      <input type="hidden" name="nama" value="<?= e($nama) ?>">

      <div class="scroll-x">
        <table class="form-table">
          <thead>
            <tr>
              <th class="l">Wilayah (SLS)</th>
              <?php foreach (PROGRES_COLS as $label): ?>
                <th><?= e($label) ?></th>
              <?php endforeach; ?>
              <th class="l">Update Terakhir</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($wilayah as $w): ?>
              <tr>
                <td class="l wil">
                  <div class="nm"><?= e($w['nmsls']) ?></div>
                  <div class="meta"><?= e($w['kode_sls']) ?></div>
                  <div class="meta"><?= e($w['nmkec']) ?> / <?= e($w['nmdesa']) ?></div>
                </td>
                <?php foreach ($colKeys as $c): ?>
                  <?php $alwaysCum = in_array($c, ALWAYS_CUMULATIVE_COLS, true); ?>
                  <td>
                    <input type="number" min="0" step="1"
                           name="row[<?= (int) $w['id'] ?>][<?= $c ?>]"
                           value="<?= (int) ($w[$c] ?? 0) ?>"
                           data-cur="<?= (int) ($w[$c] ?? 0) ?>"
                           data-additive="<?= $alwaysCum ? '0' : '1' ?>">
                  </td>
                <?php endforeach; ?>
                <td class="l meta muted"><?= e($w['updated_at'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="sticky-save">
        <div style="max-width:200px">
          <input type="password" name="pin" placeholder="Masukkan PIN" required autocomplete="off">
        </div>
        <div class="spacer"></div>
        <button class="btn" type="submit">💾 Simpan Progres</button>
      </div>
    </form>
  </div>

  <div class="chart-card">
    <div class="chart-head">
      <div>
        <h3>📈 Grafik Harian · <?= e($nama) ?></h3>
        <p class="sub">Tren <?= e(implode(', ', array_values(TIMESERIES_COLS))) ?> per tanggal (akumulasi seluruh wilayah tugas).</p>
      </div>
      <?php if ($tsHasData): ?>
        <div class="range-switch" id="petugasRange">
          <button type="button" class="active" data-range="5"><?= (int) HIST_MAX ?> Tanggal</button>
          <button type="button" data-range="all">Semua</button>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($tsHasData): ?>
      <div class="chart-box"><canvas id="petugasChart"></canvas></div>
    <?php else: ?>
      <p class="muted" style="font-size:13px">Belum ada data tren. Simpan progres pada beberapa tanggal berbeda untuk menampilkan grafik ini.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>📝 Catatan Petugas</h2>
    <p class="hint">
      Kendala lapangan, rencana tindak lanjut, dan catatan lain. Disimpan beserta riwayat &amp; waktu.
      <?php if ($catatan): ?>
        · Terakhir diperbarui: <?= e($catatan['updated_at']) ?>
      <?php endif; ?>
    </p>

    <div class="cat-layout">
      <div class="cat-form">
        <form method="post" action="/petugas.php" id="frmCatatan">
          <input type="hidden" name="action" value="save_catatan">
          <input type="hidden" name="kab" value="<?= e($kab) ?>">
          <input type="hidden" name="nama" value="<?= e($nama) ?>">

          <div class="field">
            <label>Kendala</label>
            <div class="kendala-list">
              <?php foreach (KENDALA_OPTS as $key => $label): ?>
                <label class="chk">
                  <input type="checkbox" name="kendala[]" value="<?= e($key) ?>"
                         <?= in_array($key, $catKendala, true) ? 'checked' : '' ?>
                         <?= $key === 'lainnya' ? 'data-toggle-lain' : '' ?>>
                  <span><?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <input type="text" name="kendala_lain" id="kendala_lain"
                   placeholder="Sebutkan kendala lainnya…"
                   value="<?= e($catatan['kendala_lain'] ?? '') ?>"
                   style="margin-top:8px;<?= in_array('lainnya', $catKendala, true) ? '' : 'display:none' ?>">
          </div>

          <div class="field">
            <label>Rencana Tindak Lanjut</label>
            <textarea name="rtl" rows="4" placeholder="Langkah yang akan dilakukan…"><?= e($catatan['rtl'] ?? '') ?></textarea>
          </div>

          <div class="field">
            <label>Catatan</label>
            <textarea name="catatan" rows="4" placeholder="Catatan tambahan…"><?= e($catatan['catatan'] ?? '') ?></textarea>
          </div>

          <div class="sticky-save">
            <div style="max-width:200px">
              <input type="password" name="pin" placeholder="Masukkan PIN" required autocomplete="off">
            </div>
            <div class="spacer"></div>
            <button class="btn" type="submit">💾 Simpan Catatan</button>
          </div>
        </form>
      </div>

      <div class="cat-side">
        <h3 style="margin:0 0 10px">Riwayat (<?= count($catHistory) ?>)</h3>
        <?php if ($catHistory): ?>
          <div class="cat-hist">
            <?php foreach ($catHistory as $h): ?>
              <div class="cat-item">
                <div class="cat-item-head">
                  <div class="cat-time muted"><?= e($h['created_at']) ?></div>
                  <details class="cat-del">
                    <summary title="Hapus entri ini">🗑</summary>
                    <form method="post" action="/petugas.php" class="cat-del-form"
                          onsubmit="return confirm('Hapus entri riwayat ini? Tindakan tidak dapat dibatalkan.')">
                      <input type="hidden" name="action" value="hapus_catatan">
                      <input type="hidden" name="kab" value="<?= e($kab) ?>">
                      <input type="hidden" name="nama" value="<?= e($nama) ?>">
                      <input type="hidden" name="hist_id" value="<?= (int) $h['id'] ?>">
                      <input type="password" name="pin" placeholder="PIN" required autocomplete="off">
                      <button class="btn danger" type="submit">Hapus</button>
                    </form>
                  </details>
                </div>
                <div class="cat-body">
                  <div><strong>Kendala:</strong> <?= e(kendala_labels($h['kendala'], $h['kendala_lain'])) ?></div>
                  <?php if (trim((string) $h['rtl']) !== ''): ?>
                    <div><strong>RTL:</strong> <?= nl2br(e($h['rtl'])) ?></div>
                  <?php endif; ?>
                  <?php if (trim((string) $h['catatan']) !== ''): ?>
                    <div><strong>Catatan:</strong> <?= nl2br(e($h['catatan'])) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted" style="font-size:13px">Belum ada riwayat.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>📷 Foto Plang (Bukti Sampai Lokasi)</h2>
    <p class="hint">
      Unggah foto plang/papan nama wilayah sebagai bukti telah sampai di lokasi tugas.
      Maksimal <?= (int) FOTO_MAX_PER_PETUGAS ?> foto · JPG/PNG/WebP · maks <?= (int) round(FOTO_MAX_BYTES / 1048576) ?> MB/foto.
      Tersimpan: <strong><?= count($fotoList) ?>/<?= (int) FOTO_MAX_PER_PETUGAS ?></strong>.
    </p>

    <?php if ($fotoList): ?>
      <div class="foto-grid">
        <?php foreach ($fotoList as $fp): ?>
          <figure class="foto-cell">
            <a href="/foto.php?f=<?= e(urlencode($fp['file'])) ?>" target="_blank" rel="noopener">
              <img src="/foto.php?f=<?= e(urlencode($fp['file'])) ?>" alt="Foto plang"
                   loading="lazy"
                   <?= (int) $fp['w'] > 0 ? 'width="' . (int) $fp['w'] . '" height="' . (int) $fp['h'] . '"' : '' ?>>
            </a>
            <figcaption>
              <span class="meta muted"><?= e($fp['created_at']) ?></span>
              <details class="cat-del">
                <summary title="Hapus foto ini">🗑</summary>
                <form method="post" action="/petugas.php" class="cat-del-form"
                      onsubmit="return confirm('Hapus foto ini? Tindakan tidak dapat dibatalkan.')">
                  <input type="hidden" name="action" value="hapus_foto">
                  <input type="hidden" name="kab" value="<?= e($kab) ?>">
                  <input type="hidden" name="nama" value="<?= e($nama) ?>">
                  <input type="hidden" name="foto_id" value="<?= (int) $fp['id'] ?>">
                  <input type="password" name="pin" placeholder="PIN" required autocomplete="off">
                  <button class="btn danger" type="submit">Hapus</button>
                </form>
              </details>
            </figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($fotoSisa > 0): ?>
      <form method="post" action="/petugas.php" enctype="multipart/form-data" id="frmFoto" style="margin-top:14px">
        <input type="hidden" name="action" value="upload_foto">
        <input type="hidden" name="kab" value="<?= e($kab) ?>">
        <input type="hidden" name="nama" value="<?= e($nama) ?>">

        <div class="field">
          <label>Pilih foto (bisa <?= (int) $fotoSisa ?> lagi)</label>
          <input type="file" name="foto[]" id="fotoInput" accept="image/jpeg,image/png,image/webp" multiple>
          <small class="muted" id="fotoHint">Bisa pilih beberapa sekaligus. Sisa kuota: <?= (int) $fotoSisa ?>.</small>
        </div>

        <div class="sticky-save">
          <div style="max-width:200px">
            <input type="password" name="pin" placeholder="Masukkan PIN" required autocomplete="off">
          </div>
          <div class="spacer"></div>
          <button class="btn" type="submit">⬆ Unggah Foto</button>
        </div>
      </form>
    <?php else: ?>
      <p class="muted" style="font-size:13px;margin-top:12px">
        Kuota foto sudah penuh (<?= (int) FOTO_MAX_PER_PETUGAS ?>/<?= (int) FOTO_MAX_PER_PETUGAS ?>). Hapus salah satu foto untuk mengganti.
      </p>
    <?php endif; ?>
  </div>
</div>

<style>
  /* Foto plang: masonry per kolom, pertahankan aspek rasio */
  .foto-grid{columns:4 200px;column-gap:12px}
  .foto-cell{margin:0 0 12px;break-inside:avoid;border:1px solid var(--border,#3a3a3a);
    border-radius:10px;overflow:hidden;background:var(--card,transparent)}
  .foto-cell img{display:block;width:100%;height:auto}
  .foto-cell figcaption{display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:6px 8px;font-size:11px}
  #frmFoto input[type="file"]{font-size:13px}

  /* Catatan: 2 kolom 8:4 (form : riwayat) */
  .cat-layout{display:grid;grid-template-columns:8fr 4fr;gap:18px;align-items:start}
  .cat-form textarea{width:100%;box-sizing:border-box}
  .cat-form input[type="text"]{width:100%;box-sizing:border-box}
  /* Kendala minimalis: tanpa box per opsi, daftar mengalir */
  .kendala-list{display:flex;flex-wrap:wrap;gap:6px 18px}
  .kendala-list .chk{display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px}
  .cat-hist{display:flex;flex-direction:column;gap:8px;max-height:520px;overflow:auto}
  .cat-item{padding:8px 10px;border:1px solid var(--border,#3a3a3a);border-radius:10px}
  .cat-item-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px}
  .cat-time{font-size:11px;white-space:nowrap}
  .cat-body{font-size:12.5px;line-height:1.5}
  .cat-body>div+div{margin-top:3px}
  /* Kontrol hapus per entri riwayat */
  .cat-del{position:relative}
  .cat-del>summary{list-style:none;cursor:pointer;font-size:13px;opacity:.6;line-height:1}
  .cat-del>summary::-webkit-details-marker{display:none}
  .cat-del[open]>summary,.cat-del>summary:hover{opacity:1}
  .cat-del-form{display:flex;gap:6px;align-items:center;margin-top:6px}
  .cat-del-form input[type="password"]{width:90px;font-size:12px}
  .btn.danger{background:#e5484d;color:#fff;border:none;padding:6px 10px;font-size:12px}
  .btn.danger:hover{filter:brightness(1.06)}
  @media (max-width:860px){ .cat-layout{grid-template-columns:1fr} }
</style>

<style>
  .mode-switch{display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 14px}
  .mode-opt{display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border:1px solid var(--border,#3a3a3a);border-radius:10px;cursor:pointer;flex:1;min-width:220px}
  .mode-opt input{margin-top:3px}
  .mode-opt:has(input:checked){border-color:#4a9;background:rgba(68,153,153,.08)}
  body.mode-harian .form-table input[data-additive="1"]{background:rgba(68,153,153,.10)}
</style>
<script>
(function () {
  var radios = document.querySelectorAll('input[name="mode"]');
  var inputs = document.querySelectorAll('.form-table input[data-additive]');

  function apply(mode) {
    document.body.classList.toggle('mode-harian', mode === 'harian');
    inputs.forEach(function (inp) {
      var additive = inp.getAttribute('data-additive') === '1';
      if (mode === 'harian') {
        // kolom additif: kosongkan agar petugas isi penambahan hari ini.
        // kolom always-cumulative: tetap tampilkan total terkini.
        inp.value = additive ? '' : inp.getAttribute('data-cur');
        inp.placeholder = additive ? (inp.getAttribute('data-cur') + ' + tambah') : '';
      } else {
        inp.value = inp.getAttribute('data-cur');
        inp.placeholder = '';
      }
    });
  }

  radios.forEach(function (r) {
    r.addEventListener('change', function () { if (r.checked) apply(r.value); });
  });

  var checked = document.querySelector('input[name="mode"]:checked');
  apply(checked ? checked.value : 'kumulatif');
})();

// Toggle input "Lainnya" pada card Catatan
(function () {
  var cb = document.querySelector('input[data-toggle-lain]');
  var txt = document.getElementById('kendala_lain');
  if (!cb || !txt) return;
  function sync() { txt.style.display = cb.checked ? '' : 'none'; if (!cb.checked) txt.value = ''; }
  cb.addEventListener('change', sync);
})();

// Batasi jumlah foto sesuai sisa kuota (sisi klien; server tetap menegakkan)
(function () {
  var inp  = document.getElementById('fotoInput');
  var hint = document.getElementById('fotoHint');
  if (!inp) return;
  var sisa = <?= (int) $fotoSisa ?>;
  inp.addEventListener('change', function () {
    if (inp.files.length > sisa) {
      alert('Maksimal ' + sisa + ' foto lagi. Pilihan dibatalkan, silakan pilih ulang.');
      inp.value = '';
      return;
    }
    if (hint) hint.textContent = inp.files.length
      ? (inp.files.length + ' foto dipilih (sisa kuota: ' + sisa + ').')
      : ('Bisa pilih beberapa sekaligus. Sisa kuota: ' + sisa + '.');
  });
})();

// Grafik harian petugas (Chart.js) + switch 5 / Semua tanggal
(function () {
  var el = document.getElementById('petugasChart');
  if (!el || typeof Chart === 'undefined') return;

  var LABELS = <?= json_encode($tsLabels, JSON_UNESCAPED_UNICODE) ?>;
  var SERIES = <?= json_encode($tsSeries, JSON_UNESCAPED_UNICODE) ?>;
  var COLORS = ['#f0820f', '#e23b3b', '#3b82f6', '#16a34a', '#9333ea'];
  var LAST_N = <?= (int) HIST_MAX ?>;
  var range = '5'; // default tampil 5 tanggal terakhir

  function cssVar(n){ return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }
  function slice(arr){ return range === 'all' ? arr : arr.slice(-LAST_N); }

  var chart;
  function build(){
    var grid = cssVar('--border') || '#2a2a30';
    var text = cssVar('--muted')  || '#888';
    if (chart) chart.destroy();
    chart = new Chart(el, {
      type: 'line',
      data: {
        labels: slice(LABELS),
        datasets: SERIES.map(function (s, i) {
          return {
            label: s.label,
            data: slice(s.data),
            borderColor: COLORS[i % COLORS.length],
            backgroundColor: COLORS[i % COLORS.length],
            tension: 0.25, borderWidth: 2,
            pointRadius: 3, pointHoverRadius: 5
          };
        })
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { labels: { color: text } },
          tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + c.parsed.y.toLocaleString('id-ID'); } } }
        },
        scales: {
          y: { beginAtZero: true, ticks: { color: text, callback: function (v) { return v.toLocaleString('id-ID'); } }, grid: { color: grid } },
          x: { ticks: { color: text }, grid: { display: false } }
        }
      }
    });
  }
  build();
  document.addEventListener('themechange', build);

  var sw = document.getElementById('petugasRange');
  if (sw) {
    sw.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-range]');
      if (!b) return;
      range = b.getAttribute('data-range');
      sw.querySelectorAll('button').forEach(function (x) { x.classList.toggle('active', x === b); });
      build();
    });
  }
})();
</script>
</body></html>
