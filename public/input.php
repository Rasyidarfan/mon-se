<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/helpers.php';

$kabList = list_kab();
$selKab  = $_GET['kab'] ?? array_key_first($kabList);
if (!isset($kabList[$selKab])) {
    $selKab = array_key_first($kabList);
}
$pencacah = $selKab ? pencacah_berjenjang($selKab) : [];

// Daftar kecamatan & desa unik (untuk dropdown berjenjang).
// kecMap: kdkec => nmkec ; desaList: tiap desa unik dengan kecamatan induknya.
$kecMap  = [];
$desaMap = []; // "kdkec|kddesa" => ['kec'=>..,'kode'=>..,'nama'=>..]
foreach ($pencacah as $p) {
    foreach ($p['kec'] as $k)  { $kecMap[$k['kode']] = $k['nama']; }
    foreach ($p['desa'] as $d) { $desaMap[$d['kec'] . '|' . $d['kode']] = $d; }
}
asort($kecMap);
uasort($desaMap, fn($a, $b) => [$a['kec'], $a['nama']] <=> [$b['kec'], $b['nama']]);

echo layout_head('Input Progres');
?>
<header class="topbar">
  <div class="logo">SE</div>
  <div>
    <h1><?= e(APP_TITLE) ?></h1>
    <div class="sub"><?= e(APP_SUBTITLE) ?></div>
  </div>
  <div class="spacer"></div>
  <a class="btn ghost" href="/index.php">📊 Dashboard</a>
  <?= theme_toggle() ?>
</header>

<div class="wrap">
  <div class="crumb">
    <a href="/index.php">Dashboard</a><span>›</span><span class="cur">Input Progres</span>
  </div>

  <div class="card">
    <h2>Input Progres Pendataan</h2>
    <p class="hint">
      Pilih kabupaten, lalu nama petugas. Kecamatan &amp; desa bersifat opsional —
      gunakan untuk mempersempit daftar petugas bila perlu.
    </p>

    <form method="get" action="/input.php">
      <div class="field">
        <label>Kabupaten</label>
        <select name="kab" onchange="this.form.submit()">
          <?php foreach ($kabList as $kode => $nama): ?>
            <option value="<?= e($kode) ?>" <?= $kode === $selKab ? 'selected' : '' ?>>
              <?= e($nama) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <form method="get" action="/petugas.php" id="frmPetugas">
      <input type="hidden" name="kab" value="<?= e($selKab) ?>">

      <div class="grid-2">
        <div class="field">
          <label>Kecamatan <span class="muted">(opsional)</span></label>
          <select id="filterKec">
            <option value="">— semua kecamatan —</option>
            <?php foreach ($kecMap as $kode => $nama): ?>
              <option value="<?= e($kode) ?>"><?= e($nama) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Desa/Kelurahan <span class="muted">(opsional)</span></label>
          <select id="filterDesa">
            <option value="">— semua desa —</option>
            <?php foreach ($desaMap as $key => $d): ?>
              <option value="<?= e($key) ?>" data-kec="<?= e($d['kec']) ?>">
                <?= e($d['nama']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="field">
        <label>Nama Petugas (Pencacah)</label>
        <select name="nama" id="selPetugas" required data-searchable data-placeholder="Ketik / pilih nama petugas…">
          <option value="">— pilih nama petugas —</option>
          <?php foreach ($pencacah as $p):
              // Daftar kode kecamatan & desa milik petugas (untuk filter klien).
              $kecCodes  = array_map(fn($k) => $k['kode'], $p['kec']);
              $desaCodes = array_map(fn($d) => $d['kec'] . '|' . $d['kode'], $p['desa']);
          ?>
            <option value="<?= e($p['nama']) ?>"
                    data-label="<?= e($p['nama']) ?> (<?= (int) $p['jml'] ?> wilayah)"
                    data-kec="<?= e(implode(',', $kecCodes)) ?>"
                    data-desa="<?= e(implode(',', $desaCodes)) ?>">
              <?= e($p['nama']) ?> (<?= (int) $p['jml'] ?> wilayah)
            </option>
          <?php endforeach; ?>
        </select>
        <small class="muted" id="petugasHint"><?= count($pencacah) ?> petugas tersedia.</small>
      </div>

      <button class="btn" type="submit">Buka Form Tugas →</button>
    </form>
  </div>

  <p class="muted" style="font-size:12px">
    Total <?= count($pencacah) ?> petugas pada <?= e($kabList[$selKab] ?? '-') ?>.
  </p>
</div>

<style>
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media (max-width:640px){ .grid-2{grid-template-columns:1fr} }
</style>
<script src="/assets/searchable.js"></script>
<script>
(function () {
  var selKec   = document.getElementById('filterKec');
  var selDesa  = document.getElementById('filterDesa');
  var selPet   = document.getElementById('selPetugas');
  var hint     = document.getElementById('petugasHint');
  if (!selKec || !selDesa || !selPet) return;

  var desaOpts = Array.prototype.slice.call(selDesa.options);
  var petOpts  = Array.prototype.slice.call(selPet.options);
  var total    = petOpts.filter(function (o) { return o.value; }).length;

  // Batasi pilihan desa sesuai kecamatan terpilih.
  function syncDesaOptions() {
    var kec = selKec.value;
    desaOpts.forEach(function (o) {
      if (!o.value) return; // placeholder
      o.hidden = kec ? (o.getAttribute('data-kec') !== kec) : false;
    });
    // Bila desa terpilih tak lagi cocok dengan kecamatan, reset.
    if (selDesa.value) {
      var cur = selDesa.options[selDesa.selectedIndex];
      if (cur && cur.hidden) selDesa.value = '';
    }
  }

  // Filter daftar petugas berdasarkan kecamatan/desa terpilih.
  function filterPetugas() {
    var kec  = selKec.value;
    var desa = selDesa.value; // format "kdkec|kddesa"
    var shown = 0;
    petOpts.forEach(function (o) {
      if (!o.value) return; // placeholder
      var hit = true;
      if (desa) {
        hit = (',' + (o.getAttribute('data-desa') || '') + ',').indexOf(',' + desa + ',') !== -1;
      } else if (kec) {
        hit = (',' + (o.getAttribute('data-kec') || '') + ',').indexOf(',' + kec + ',') !== -1;
      }
      o.hidden = !hit;
      if (hit) shown++;
    });
    if (hint) {
      hint.textContent = (kec || desa)
        ? (shown + ' dari ' + total + ' petugas cocok dengan filter.')
        : (total + ' petugas tersedia.');
    }
    // Beri tahu searchable agar refresh & reset bila pilihan jadi tak valid.
    if (typeof selPet.ssRefresh === 'function') selPet.ssRefresh();
  }

  selKec.addEventListener('change', function () { syncDesaOptions(); filterPetugas(); });
  selDesa.addEventListener('change', filterPetugas);

  syncDesaOptions();
  filterPetugas();
})();
</script>
</body></html>
