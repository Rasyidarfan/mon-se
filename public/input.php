<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/helpers.php';

$kabList = list_kab();
$selKab  = $_GET['kab'] ?? array_key_first($kabList);
if (!isset($kabList[$selKab])) {
    $selKab = array_key_first($kabList);
}
$pencacah = $selKab ? list_pencacah($selKab) : [];

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
    <p class="hint">Pilih kabupaten dan nama petugas (pencacah). Form berisi seluruh wilayah tugas akan ditampilkan.</p>

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

    <form method="get" action="/petugas.php">
      <input type="hidden" name="kab" value="<?= e($selKab) ?>">
      <div class="field">
        <label>Nama Petugas (Pencacah)</label>
        <select name="nama" required data-searchable data-placeholder="Ketik / pilih nama petugas…">
          <option value="">— pilih nama petugas —</option>
          <?php foreach ($pencacah as $p): ?>
            <option value="<?= e($p['nama_pencacah']) ?>"
                    data-label="<?= e($p['nama_pencacah']) ?> (<?= (int) $p['jml'] ?> wilayah)">
              <?= e($p['nama_pencacah']) ?> (<?= (int) $p['jml'] ?> wilayah)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn" type="submit">Buka Form Tugas →</button>
    </form>
  </div>

  <p class="muted" style="font-size:12px">
    Total <?= count($pencacah) ?> petugas pada <?= e($kabList[$selKab] ?? '-') ?>.
  </p>
</div>
<script src="/assets/searchable.js"></script>
</body></html>
