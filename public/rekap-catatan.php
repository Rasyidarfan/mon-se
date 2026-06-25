<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/helpers.php';

$kabList = list_kab();
$kab = $_GET['kab'] ?? '';
$tgl = $_GET['tgl'] ?? '';
if ($kab !== '' && !isset($kabList[$kab])) { $kab = ''; }

$f = [];
if ($kab !== '') { $f['kab'] = $kab; }
if ($tgl !== '') { $f['tgl'] = $tgl; }

$groups   = rekap_catatan($f);
$tglOpts  = tanggal_catatan($kab !== '' ? ['kab' => $kab] : []);

// Hitung total entri & petugas unik (untuk ringkasan)
$totItem    = 0;
$petugasSet = [];
foreach ($groups as $g) {
    $totItem += count($g['items']);
    foreach ($g['items'] as $it) {
        $petugasSet[$it['kdkab'] . '|' . $it['nama_pencacah']] = true;
    }
}

$fmtTgl = function (string $d): string {
    $ts = strtotime($d);
    if (!$ts) return $d;
    $hari  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
              'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $hari[(int) date('w', $ts)] . ', ' . (int) date('j', $ts)
         . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
};

echo layout_head('Rekap Catatan Petugas', false);
?>
<header class="topbar">
  <div class="logo">SE</div>
  <div>
    <h1>Rekap Catatan Petugas</h1>
    <div class="sub"><?= e(APP_SUBTITLE) ?></div>
  </div>
  <div class="spacer"></div>
  <a class="btn ghost" href="/index.php">← Dashboard</a>
  <?= theme_toggle() ?>
</header>

<div class="wrap">
  <div class="crumb">
    <a href="/index.php">Dashboard</a>
    <span>›</span>
    <span class="cur">Rekap Catatan</span>
  </div>

  <!-- Filter -->
  <div class="card">
    <form method="get" action="/rekap-catatan.php" class="filter-bar">
      <div class="field">
        <label>Kabupaten</label>
        <select name="kab" onchange="this.form.submit()">
          <option value="">Semua Kabupaten</option>
          <?php foreach ($kabList as $code => $nm): ?>
            <option value="<?= e($code) ?>" <?= $code === $kab ? 'selected' : '' ?>><?= e($nm) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Tanggal</label>
        <select name="tgl" onchange="this.form.submit()">
          <option value="">Semua Tanggal</option>
          <?php foreach ($tglOpts as $d): ?>
            <option value="<?= e($d) ?>" <?= $d === $tgl ? 'selected' : '' ?>><?= e($fmtTgl($d)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="flex:1;min-width:160px">
        <label>Cari petugas/isi</label>
        <input type="text" id="q" placeholder="🔍 Cari nama, kendala, atau catatan…" oninput="filterCatatan()">
      </div>
      <noscript><button class="btn" type="submit">Terapkan</button></noscript>
    </form>
  </div>

  <!-- Ringkasan -->
  <div class="grid-stats">
    <div class="stat"><div class="v"><?= nf(count($groups)) ?></div><div class="k">Tanggal Dengan Catatan</div></div>
    <div class="stat"><div class="v"><?= nf($totItem) ?></div><div class="k">Total Entri Catatan</div></div>
    <div class="stat"><div class="v"><?= nf(count($petugasSet)) ?></div><div class="k">Petugas Mencatat</div></div>
  </div>

  <?php if (!$groups): ?>
    <div class="card"><p class="muted">Belum ada catatan untuk filter ini.</p></div>
  <?php endif; ?>

  <?php foreach ($groups as $g): ?>
    <div class="card rekap-day">
      <h2 class="day-head">
        📅 <?= e($fmtTgl($g['tanggal'])) ?>
        <span class="muted day-count"><?= count($g['items']) ?> entri</span>
      </h2>
      <div class="scroll-x">
        <table class="rekap-tbl">
          <thead>
            <tr>
              <th class="l" style="width:14%">Waktu</th>
              <th class="l" style="width:20%">Petugas</th>
              <th class="l" style="width:22%">Kendala</th>
              <th class="l" style="width:22%">Rencana Tindak Lanjut</th>
              <th class="l" style="width:22%">Catatan</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($g['items'] as $it):
                $kab1 = (string) $it['kdkab'];
                $nama = (string) $it['nama_pencacah'];
                $haystack = strtolower(
                    $nama . ' ' . ($it['nmkab'] ?? '') . ' '
                    . kendala_labels($it['kendala'], (string) $it['kendala_lain'])
                    . ' ' . $it['rtl'] . ' ' . $it['catatan']
                );
            ?>
              <tr class="rekap-row" data-search="<?= e($haystack) ?>">
                <td class="l meta muted" style="white-space:nowrap">
                  <?= e(date('H:i', strtotime((string) $it['created_at']))) ?>
                </td>
                <td class="l">
                  <a class="petugas-link" href="/petugas.php?kab=<?= e($kab1) ?>&nama=<?= e(urlencode($nama)) ?>">
                    <?= e($nama) ?>
                  </a>
                  <?php if (!empty($it['nmkab'])): ?>
                    <div class="meta muted"><?= e($it['nmkab']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="l rekap-cell"><?= e(kendala_labels($it['kendala'], (string) $it['kendala_lain'])) ?></td>
                <td class="l rekap-cell"><?= trim((string) $it['rtl']) !== '' ? nl2br(e($it['rtl'])) : '<span class="muted">—</span>' ?></td>
                <td class="l rekap-cell"><?= trim((string) $it['catatan']) !== '' ? nl2br(e($it['catatan'])) : '<span class="muted">—</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<style>
  .filter-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin:0}
  .filter-bar .field{display:flex;flex-direction:column;gap:5px}
  .filter-bar label{font-size:12px;color:var(--muted,#888)}
  .filter-bar select,.filter-bar input[type="text"]{padding:8px 10px;border-radius:8px;
    border:1px solid var(--border,#3a3a3a);background:transparent;color:inherit;font-size:13px}
  .rekap-day{padding:0;overflow:hidden}
  .day-head{display:flex;align-items:center;gap:10px;margin:0;padding:14px 18px;
    border-bottom:1px solid var(--border,#2a2a30);font-size:16px}
  .day-count{font-size:12px;font-weight:normal}
  .rekap-tbl{width:100%;border-collapse:collapse;font-size:13px}
  .rekap-tbl th{text-align:left;padding:10px 14px;color:var(--muted,#888);font-weight:600;
    border-bottom:1px solid var(--border,#2a2a30)}
  .rekap-tbl td{padding:11px 14px;border-bottom:1px solid var(--border,#2a2a30);vertical-align:top}
  .rekap-tbl tr:last-child td{border-bottom:none}
  .rekap-cell{line-height:1.5}
  .petugas-link{color:var(--accent,#f0820f);text-decoration:none;font-weight:600}
  .petugas-link:hover{text-decoration:underline}
</style>
<script>
function filterCatatan(){
  var q = document.getElementById('q').value.toLowerCase().trim();
  document.querySelectorAll('.rekap-day').forEach(function(day){
    var shown = 0;
    day.querySelectorAll('.rekap-row').forEach(function(tr){
      var hit = !q || (tr.getAttribute('data-search') || '').indexOf(q) !== -1;
      tr.style.display = hit ? '' : 'none';
      if (hit) shown++;
    });
    // Sembunyikan kartu tanggal bila tak ada baris cocok.
    day.style.display = shown ? '' : 'none';
  });
}
</script>
</body></html>
