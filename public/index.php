<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/helpers.php';

$kabList = list_kab();
$kab = $_GET['kab'] ?? '';
$kec = $_GET['kec'] ?? '';
if ($kab !== '' && !isset($kabList[$kab])) { $kab = ''; $kec = ''; }

// Tentukan level drill-down
if ($kab === '') {
    $level = 'kab';
} elseif ($kec === '') {
    $level = 'kec';
} else {
    $level = 'desa';
}

$rows    = rekap($level, ['kab' => $kab, 'kec' => $kec]);
$colKeys = array_keys(PROGRES_COLS);

// Nama untuk breadcrumb / judul
$kecName = '';
if ($kec !== '' && $rows) {
    // ambil nama kecamatan dari salah satu baris desa
    $kecName = $rows[0]['nmkec'] ?? '';
}
// Bila level desa, nama kec belum ada di rows desa → ambil terpisah
if ($level === 'desa') {
    $st = db()->prepare('SELECT nmkec FROM wilayah WHERE kdkab=:a AND kdkec=:b LIMIT 1');
    $st->execute([':a' => $kab, ':b' => $kec]);
    $kecName = (string) $st->fetchColumn();
}

// Total keseluruhan
$grand = array_fill_keys($colKeys, 0);
foreach ($rows as $r) {
    foreach ($colKeys as $c) {
        $grand[$c] += (int) $r[$c];
    }
}
$pctVal = fn(int $resp, int $pre): float => $pre > 0 ? round($resp / $pre * 100, 2) : 0.0;
$pct    = fn(int $resp, int $pre) => number_format($pctVal($resp, $pre), 2, ',', '.') . '%';

// Data untuk grafik (% responden per baris)
$chartLabels = [];
$chartData   = [];
$chartTim    = []; // label Tim sejajar tiap baris (kosong bila bukan level kecamatan)
foreach ($rows as $r) {
    $tim = trim((string) ($r['tim'] ?? ''));
    // Pada level kecamatan, sertakan nama Tim di label grafik agar tampil bersama capaian.
    $chartLabels[] = $tim !== '' ? $r['name'] . ' · ' . $tim : $r['name'];
    $chartData[]   = $pctVal((int) $r['responden'], (int) $r['prelist']);
    $chartTim[]    = $tim;
}

// Data grafik "Capaian Responden per Kabupaten dan per Tim" (agregat per Tim, scope kab saat ini)
$timRows    = ($level !== 'desa') ? rekap_tim(['kab' => $kab]) : [];
$timLabels  = [];
$timData    = [];
foreach ($timRows as $tr) {
    $timLabels[] = $tr['tim'];
    $timData[]   = $pctVal((int) $tr['responden'], (int) $tr['prelist']);
}
// Tampilkan grafik per-Tim hanya bila ada minimal satu Tim selain "Tanpa Tim".
$timHasData = (bool) array_filter($timLabels, fn($l) => $l !== 'Tanpa Tim');
$timScope   = $kab !== '' ? ($kabList[$kab] ?? '') : 'Semua Kabupaten';

// Data grafik timeseries (tren beberapa kolom per tanggal snapshot, scope drill saat ini)
$ts = rekap_timeseries(['kab' => $kab, 'kec' => $kec]);
$tsLabels = array_map(fn($d) => date('d/m', strtotime($d)), $ts['dates']);
$tsSeries = [];
foreach (TIMESERIES_COLS as $key => $label) {
    $tsSeries[] = ['label' => $label, 'data' => $ts['series'][$key] ?? []];
}
$tsHasData = count($ts['dates']) > 0;

// Judul level
$levelTitle = ['kab' => 'Semua Kabupaten', 'kec' => $kabList[$kab] ?? '', 'desa' => $kecName];
$childLabel = ['kab' => 'Kabupaten', 'kec' => 'Kecamatan', 'desa' => 'Desa/Kelurahan'][$level];
$childUnit  = ['kab' => 'kecamatan', 'kec' => 'desa', 'desa' => 'SLS'][$level];

// URL drill untuk tiap baris
function drill_url(string $level, string $kab, array $r): string
{
    if ($level === 'kab')  return '/index.php?kab=' . urlencode($r['code']);
    if ($level === 'kec')  return '/index.php?kab=' . urlencode($kab) . '&kec=' . urlencode($r['code']);
    return ''; // level desa = daun, tidak drill lebih jauh
}

echo layout_head('Dashboard Progres', true);
?>
<header class="topbar">
  <div class="logo">SE</div>
  <div>
    <h1>Progres Pendataan</h1>
    <div class="sub"><?= e(APP_SUBTITLE) ?></div>
  </div>
  <div class="spacer"></div>
  <a class="btn ghost" href="/input.php?kab=<?= e($kab) ?>">+ Input Progres</a>
  <a class="btn ghost" href="/rekap-catatan.php<?= $kab ? '?kab=' . e($kab) : '' ?>">📝 Rekap Catatan</a>
  <a class="btn ghost" href="/galeri.php<?= $kab ? '?kab=' . e($kab) : '' ?>">📷 Galeri Plang</a>
  <a class="btn ghost" href="/export.php<?= $kab ? '?kab=' . e($kab) . ($kec ? '&kec=' . e($kec) : '') : '' ?>">⬇ CSV</a>
  <?= theme_toggle() ?>
</header>

<div class="wrap">
  <!-- Breadcrumb drill-down -->
  <div class="crumb">
    <a href="/index.php">Semua Kabupaten</a>
    <?php if ($kab !== ''): ?>
      <span>›</span>
      <?php if ($kec !== ''): ?>
        <a href="/index.php?kab=<?= e($kab) ?>"><?= e($kabList[$kab]) ?></a>
        <span>›</span><span class="cur"><?= e($kecName) ?></span>
      <?php else: ?>
        <span class="cur"><?= e($kabList[$kab]) ?></span>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Kartu ringkasan -->
  <div class="grid-stats">
    <div class="stat">
      <div class="v"><?= $pct((int) $grand['responden'], (int) $grand['prelist']) ?></div>
      <div class="k">Capaian Responden vs Prelist</div>
    </div>
    <div class="stat"><div class="v"><?= nf($grand['prelist']) ?></div><div class="k">Prelist Usaha &amp; Keluarga</div></div>
    <div class="stat"><div class="v"><?= nf($grand['responden']) ?></div><div class="k">Responden Didata</div></div>
    <div class="stat"><div class="v"><?= nf($grand['ub'] + $grand['um'] + $grand['umk']) ?></div><div class="k">UB+UM+UMK Didata</div></div>
  </div>

  <!-- Tabel rekap -->
  <div class="card" style="padding:0; overflow:hidden">
    <div class="toolbar" style="padding:14px 18px 0; margin:0">
      <span class="muted">Rincian per <?= e($childLabel) ?> (<?= count($rows) ?>)</span>
      <div class="spacer"></div>
      <div class="search"><input type="text" id="q" placeholder="🔍 Cari <?= e(strtolower($childLabel)) ?>…" oninput="filterRows()"></div>
    </div>
    <div class="scroll-x">
      <table id="tbl">
        <thead>
          <tr>
            <th class="l">Kode</th>
            <th class="l"><?= e($childLabel) ?></th>
            <th class="l">Capaian</th>
            <?php foreach (DASHBOARD_COLS as $label): ?>
              <th><?= e($label) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
              $url = drill_url($level, $kab, $r);
              $p   = $pctVal((int) $r['responden'], (int) $r['prelist']);
          ?>
            <tr class="<?= $url ? 'drill' : '' ?>" <?= $url ? 'onclick="location.href=\'' . e($url) . '\'"' : '' ?>>
              <td class="l kode"><?= e($r['code_disp']) ?></td>
              <?php $tim = trim((string) ($r['tim'] ?? '')); ?>
              <td class="l search-key">
                <?= e($r['name']) ?>
                <?php if ($tim !== ''): ?><span class="tim-badge"><?= e($tim) ?></span><?php endif; ?>
                <?php if ($url): ?><span class="chev">›</span><?php endif; ?>
                <div class="meta muted" style="font-size:11px"><?= (int) $r['child_count'] ?> <?= e($childUnit) ?></div>
              </td>
              <td class="l">
                <span class="smallbar-track"><span class="smallbar" style="width:<?= min(100, $p) ?>%"></span></span>
                <span class="pct"><?= number_format($p, 2, ',', '.') ?>%</span>
              </td>
              <td class="num"><?= nf((int) $r['prelist']) ?></td>
              <td class="num"><?= nf((int) $r['responden']) ?></td>
              <td class="num"><?= nf((int) $r['usaha_tdk_temu']) ?></td>
              <td class="num"><?= nf((int) $r['ub']) ?></td>
              <td class="num"><?= nf((int) $r['um']) ?></td>
              <td class="num"><?= nf((int) $r['umk']) ?></td>
              <td class="num"><?= nf((int) $r['usaha_keluarga']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="total-row">
            <td class="l" colspan="2">Total Keseluruhan (<?= count($rows) ?> <?= e(strtolower($childLabel)) ?>)</td>
            <td class="l"><?= $pct((int) $grand['responden'], (int) $grand['prelist']) ?></td>
            <td class="num"><?= nf($grand['prelist']) ?></td>
            <td class="num"><?= nf($grand['responden']) ?></td>
            <td class="num"><?= nf($grand['usaha_tdk_temu']) ?></td>
            <td class="num"><?= nf($grand['ub']) ?></td>
            <td class="num"><?= nf($grand['um']) ?></td>
            <td class="num"><?= nf($grand['umk']) ?></td>
            <td class="num"><?= nf($grand['usaha_keluarga']) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <!-- Grafik -->
  <div class="chart-card">
    <h3>Capaian Responden per <?= e($childLabel) ?> · <?= e($levelTitle[$level]) ?></h3>
    <p class="sub">Persentase responden didata terhadap prelist (kolom 4 ÷ kolom 3).</p>
    <div class="chart-box"><canvas id="chart"></canvas></div>
  </div>

  <!-- Grafik capaian per Tim -->
  <?php if ($timHasData): ?>
  <div class="chart-card">
    <h3>Capaian Responden per Kabupaten dan per Tim · <?= e($timScope) ?></h3>
    <p class="sub">Persentase responden didata terhadap prelist, diagregasi per Tim petugas.</p>
    <div class="chart-box"><canvas id="timchart"></canvas></div>
  </div>
  <?php endif; ?>

  <!-- Grafik timeseries (tren per tanggal) -->
  <div class="chart-card">
    <div class="chart-head">
      <div>
        <h3>Tren Harian · <?= e($levelTitle[$level]) ?></h3>
        <p class="sub">Perkembangan jumlah Responden, Reject, dan Kunjungan Ulang per tanggal penyimpanan.</p>
      </div>
      <?php if ($tsHasData): ?>
        <div class="range-switch" id="tsRange">
          <button type="button" class="active" data-range="5"><?= (int) HIST_MAX ?> Tanggal</button>
          <button type="button" data-range="all">Semua</button>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($tsHasData): ?>
      <div class="chart-box"><canvas id="tschart"></canvas></div>
    <?php else: ?>
      <p class="muted" style="font-size:13px">Belum ada data tren. Simpan progres pada beberapa tanggal berbeda untuk menampilkan grafik ini.</p>
    <?php endif; ?>
  </div>

  <p class="muted" style="font-size:12px">
    Klik baris untuk melihat rincian lebih dalam (Kabupaten → Kecamatan → Desa).
  </p>
</div>

<script>
const LABELS = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
const DATA   = <?= json_encode($chartData) ?>;

function cssVar(n){ return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }

let chart;
function buildChart(){
  const ctx = document.getElementById('chart');
  const accent = cssVar('--accent') || '#f0820f';
  const grid   = cssVar('--border') || '#2a2a30';
  const text   = cssVar('--muted')  || '#888';
  if (chart) chart.destroy();
  chart = new Chart(ctx, {
    type: 'bar',
    data: { labels: LABELS, datasets: [{
      label: '% Responden', data: DATA,
      backgroundColor: accent, borderRadius: 6, maxBarThickness: 46
    }]},
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false },
        tooltip: { callbacks: { label: c => c.parsed.y.toLocaleString('id-ID') + '%' } } },
      scales: {
        y: { beginAtZero: true, suggestedMax: 100, ticks: { color: text, callback: v => v + '%' }, grid: { color: grid } },
        x: { ticks: { color: text, autoSkip: false, maxRotation: 60, minRotation: 0 }, grid: { display: false } }
      }
    }
  });
}
buildChart();
document.addEventListener('themechange', buildChart);

// --- Grafik capaian per Tim ---
const TIM_LABELS = <?= json_encode($timLabels, JSON_UNESCAPED_UNICODE) ?>;
const TIM_DATA   = <?= json_encode($timData) ?>;

let timChart;
function buildTimChart(){
  const ctx = document.getElementById('timchart');
  if (!ctx) return;
  const accent = cssVar('--accent') || '#f0820f';
  const grid   = cssVar('--border') || '#2a2a30';
  const text   = cssVar('--muted')  || '#888';
  if (timChart) timChart.destroy();
  timChart = new Chart(ctx, {
    type: 'bar',
    data: { labels: TIM_LABELS, datasets: [{
      label: '% Responden', data: TIM_DATA,
      backgroundColor: accent, borderRadius: 6, maxBarThickness: 46
    }]},
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false },
        tooltip: { callbacks: { label: c => c.parsed.y.toLocaleString('id-ID') + '%' } } },
      scales: {
        y: { beginAtZero: true, suggestedMax: 100, ticks: { color: text, callback: v => v + '%' }, grid: { color: grid } },
        x: { ticks: { color: text, autoSkip: false, maxRotation: 60, minRotation: 0 }, grid: { display: false } }
      }
    }
  });
}
buildTimChart();
document.addEventListener('themechange', buildTimChart);

// --- Grafik timeseries (multi-line) ---
const TS_LABELS = <?= json_encode($tsLabels, JSON_UNESCAPED_UNICODE) ?>;
const TS_SERIES = <?= json_encode($tsSeries, JSON_UNESCAPED_UNICODE) ?>;
const TS_COLORS = ['#f0820f', '#e23b3b', '#3b82f6', '#16a34a', '#9333ea'];
const TS_LAST_N = <?= (int) HIST_MAX ?>;
let tsRange = '5'; // default 5 tanggal terakhir
const tsSlice = arr => tsRange === 'all' ? arr : arr.slice(-TS_LAST_N);

let tsChart;
function buildTsChart(){
  const el = document.getElementById('tschart');
  if (!el) return;
  const grid = cssVar('--border') || '#2a2a30';
  const text = cssVar('--muted')  || '#888';
  if (tsChart) tsChart.destroy();
  tsChart = new Chart(el, {
    type: 'line',
    data: {
      labels: tsSlice(TS_LABELS),
      datasets: TS_SERIES.map((s, i) => ({
        label: s.label,
        data: tsSlice(s.data),
        borderColor: TS_COLORS[i % TS_COLORS.length],
        backgroundColor: TS_COLORS[i % TS_COLORS.length],
        tension: 0.25, borderWidth: 2,
        pointRadius: 3, pointHoverRadius: 5
      }))
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: text } },
        tooltip: { callbacks: { label: c => c.dataset.label + ': ' + c.parsed.y.toLocaleString('id-ID') } }
      },
      scales: {
        y: { beginAtZero: true, ticks: { color: text, callback: v => v.toLocaleString('id-ID') }, grid: { color: grid } },
        x: { ticks: { color: text }, grid: { display: false } }
      }
    }
  });
}
buildTsChart();
document.addEventListener('themechange', buildTsChart);

const tsRangeEl = document.getElementById('tsRange');
if (tsRangeEl) {
  tsRangeEl.addEventListener('click', ev => {
    const b = ev.target.closest('button[data-range]');
    if (!b) return;
    tsRange = b.getAttribute('data-range');
    tsRangeEl.querySelectorAll('button').forEach(x => x.classList.toggle('active', x === b));
    buildTsChart();
  });
}

function filterRows() {
  const q = document.getElementById('q').value.toLowerCase();
  document.querySelectorAll('#tbl tbody tr').forEach(tr => {
    const k = tr.querySelector('.search-key').textContent.toLowerCase();
    tr.style.display = k.includes(q) ? '' : 'none';
  });
}
</script>
</body></html>
