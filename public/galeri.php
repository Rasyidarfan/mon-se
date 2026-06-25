<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/helpers.php';

$kabList = list_kab();
$kab = $_GET['kab'] ?? '';
if ($kab !== '' && !isset($kabList[$kab])) { $kab = ''; }

$fotos = foto_semua($kab !== '' ? ['kab' => $kab] : []);

// Jumlah petugas unik yang sudah upload (untuk ringkasan)
$petugasSet = [];
foreach ($fotos as $fp) {
    $petugasSet[$fp['kdkab'] . '|' . $fp['nama_pencacah']] = true;
}

echo layout_head('Galeri Foto Plang', false);
?>
<header class="topbar">
  <div class="logo">SE</div>
  <div>
    <h1>Galeri Foto Plang</h1>
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
    <span class="cur">Galeri Foto Plang</span>
  </div>

  <div class="card">
    <form method="get" action="/galeri.php" class="filter-bar">
      <div class="field">
        <label>Kabupaten</label>
        <select name="kab" onchange="this.form.submit()">
          <option value="">Semua Kabupaten</option>
          <?php foreach ($kabList as $code => $nm): ?>
            <option value="<?= e($code) ?>" <?= $code === $kab ? 'selected' : '' ?>><?= e($nm) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field" style="flex:1;min-width:160px">
        <label>Cari petugas</label>
        <input type="text" id="q" placeholder="🔍 Cari nama petugas atau kabupaten…" oninput="filterFoto()">
      </div>
      <noscript><button class="btn" type="submit">Terapkan</button></noscript>
    </form>
  </div>

  <div class="grid-stats">
    <div class="stat"><div class="v"><?= nf(count($fotos)) ?></div><div class="k">Total Foto Plang</div></div>
    <div class="stat"><div class="v"><?= nf(count($petugasSet)) ?></div><div class="k">Petugas Sudah Upload</div></div>
  </div>

  <?php if (!$fotos): ?>
    <div class="card"><p class="muted">Belum ada foto plang untuk filter ini.</p></div>
  <?php else: ?>
    <div class="gal-grid" id="galGrid">
      <?php foreach ($fotos as $fp):
          $nama = (string) $fp['nama_pencacah'];
          $url  = '/foto.php?f=' . urlencode((string) $fp['file']);
          $key  = strtolower($nama . ' ' . ($fp['nmkab'] ?? ''));
      ?>
        <figure class="gal-cell" data-search="<?= e($key) ?>">
          <a href="/petugas.php?kab=<?= e((string) $fp['kdkab']) ?>&nama=<?= e(urlencode($nama)) ?>"
             title="Buka form petugas <?= e($nama) ?>">
            <img src="<?= e($url) ?>" alt="Foto plang <?= e($nama) ?>" loading="lazy"
                 <?= (int) $fp['w'] > 0 ? 'width="' . (int) $fp['w'] . '" height="' . (int) $fp['h'] . '"' : '' ?>>
          </a>
          <figcaption>
            <span class="gal-nama"><?= e($nama) ?></span>
            <?php if (!empty($fp['nmkab'])): ?>
              <span class="meta muted"><?= e($fp['nmkab']) ?></span>
            <?php endif; ?>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<style>
  .filter-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin:0}
  .filter-bar .field{display:flex;flex-direction:column;gap:5px}
  .filter-bar label{font-size:12px;color:var(--muted,#888)}
  .filter-bar select,.filter-bar input[type="text"]{padding:8px 10px;border-radius:8px;
    border:1px solid var(--border,#3a3a3a);background:transparent;color:inherit;font-size:13px}

  /* Galeri masonry per kolom — pertahankan aspek rasio asli tiap foto */
  .gal-grid{columns:5 220px;column-gap:14px}
  .gal-cell{margin:0 0 14px;break-inside:avoid;border:1px solid var(--border,#3a3a3a);
    border-radius:12px;overflow:hidden;background:var(--card,transparent)}
  .gal-cell img{display:block;width:100%;height:auto}
  .gal-cell figcaption{display:flex;flex-direction:column;gap:2px;padding:8px 10px}
  .gal-nama{font-weight:600;font-size:13px}
</style>
<script>
function filterFoto(){
  var q = document.getElementById('q').value.toLowerCase().trim();
  document.querySelectorAll('.gal-cell').forEach(function(c){
    var hit = !q || (c.getAttribute('data-search') || '').indexOf(q) !== -1;
    c.style.display = hit ? '' : 'none';
  });
}
</script>
</body></html>
