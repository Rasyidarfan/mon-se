<?php
// Konfigurasi aplikasi monitoring Sensus Ekonomi BPS
declare(strict_types=1);

const APP_TITLE = 'Monitoring Sensus Ekonomi';
const APP_SUBTITLE = 'BPS Kabupaten Jayawijaya';

// PIN tunggal untuk semua petugas (ubah sesuai kebutuhan)
const PETUGAS_PIN = '970256';

// Lokasi file
const DB_PATH     = __DIR__ . '/../data/monitoring.sqlite';
const JSON_PATH   = __DIR__ . '/../tableConvert.com_ok842w.json';
const MUATAN_PATH = __DIR__ . '/../muatan_per_wilayah.json';
const TIM_PATH    = __DIR__ . '/../data/tim_kec.json';

// Kolom input progres: kunci => label
const PROGRES_COLS = [
    'prelist'         => 'Jumlah Prelist Usaha & Keluarga',
    'responden'       => 'Jumlah Responden Didata',
    'usaha_tdk_temu'  => 'Jumlah Usaha Tidak Ditemukan',
    'ub'              => 'Jumlah UB Didata',
    'um'              => 'Jumlah UM Didata',
    'umk'             => 'Jumlah UMK Didata',
    'usaha_keluarga'  => 'Jumlah Usaha dalam Keluarga Didata',
    'reject'          => 'Jumlah Reject',
    'kunjungan_ulang' => 'Jumlah Kunjungan Ulang',
];

// Kolom yang SELALU kumulatif (menimpa nilai lama) walau input pakai mode harian.
// Kolom lain pada mode harian akan ditambahkan ke nilai sebelumnya.
const ALWAYS_CUMULATIVE_COLS = ['prelist', 'reject', 'kunjungan_ulang'];

// Kolom yang ditampilkan pada tabel "Rincian per wilayah" di dashboard.
// Subset dari PROGRES_COLS (reject & kunjungan_ulang sengaja tidak ditampilkan di sini).
const DASHBOARD_COLS = [
    'prelist'        => 'Jumlah Prelist Usaha & Keluarga',
    'responden'      => 'Jumlah Responden Didata',
    'usaha_tdk_temu' => 'Jumlah Usaha Tidak Ditemukan',
    'ub'             => 'Jumlah UB Didata',
    'um'             => 'Jumlah UM Didata',
    'umk'            => 'Jumlah UMK Didata',
    'usaha_keluarga' => 'Jumlah Usaha dalam Keluarga Didata',
];

// Kolom yang ditampilkan sebagai garis pada grafik timeseries dashboard:
// kunci kolom => label legenda
const TIMESERIES_COLS = [
    'responden'       => 'Responden',
    'reject'          => 'Reject',
    'kunjungan_ulang' => 'Kunjungan Ulang',
];

// Jumlah snapshot tanggal terakhir yang disimpan untuk grafik timeseries
const HIST_MAX = 5;

// Foto plang (bukti sampai di lokasi tugas)
const UPLOAD_DIR          = __DIR__ . '/../data/uploads'; // privat, di luar web root; tahan deploy
const FOTO_MAX_PER_PETUGAS = 5;                            // batas foto per petugas
const FOTO_MAX_BYTES       = 6 * 1024 * 1024;             // 6 MB per file
// Tipe gambar yang diterima: mime => ekstensi
const FOTO_MIME_EXT = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// Pilihan kendala (checkbox) pada card Catatan Petugas: kunci => label.
// Kunci 'lainnya' disertai input teks bebas.
const KENDALA_OPTS = [
    'tidak_ada'        => 'Tidak Ada Kendala',
    'akses_wilayah'    => 'Akses Wilayah',
    'responden_kosong' => 'Responden Tidak di Rumah',
    'responden_tolak'  => 'Responden Menolak',
    'jaringan_aplikasi'=> 'Jaringan/Aplikasi',
    'peta_sls'         => 'Peta/SLS Tidak Sesuai',
    'petugas_sakit'    => 'Petugas Sakit/Tidak Aktif',
    'lainnya'          => 'Lainnya',
];
