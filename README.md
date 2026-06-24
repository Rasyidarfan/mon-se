# Monitoring Manual Sensus Ekonomi — BPS Kabupaten Jayawijaya

Aplikasi web **monitoring manual** progres pendataan Sensus Ekonomi. Petugas
(pencacah) **menginput sendiri** capaian lapangannya melalui formulir; angka tidak
ditarik otomatis dari sistem lain. Dashboard menampilkan rekap progres bertingkat
beserta grafik.

> **Monitoring manual** — seluruh angka progres (responden didata, UB/UM/UMK, dll.)
> diisi secara manual oleh petugas dan dapat diperbarui berkali-kali. Aplikasi ini
> hanya merekap dan memvisualisasikan input tersebut.

## Cakupan data

- **3 kabupaten**: Jayawijaya, Mamberamo Tengah, Yalimo
- **1.595 wilayah (SLS)** sebagai tugas pencacah
- **348 pencacah**
- **50 kecamatan** dipetakan ke **Tim petugas** (`data/tim_kec.json`)
- **Jumlah Prelist** di-seed dari data muatan per wilayah; kolom progres lain mulai dari 0
  dan diisi manual oleh petugas.

> **Sumber data utama adalah `data/monitoring.sqlite`** yang sudah ter-seed dan ikut
> di-commit. Berkas sumber mentah (`tableConvert.com_ok842w.json`,
> `muatan_per_wilayah.json`) hanya dipakai untuk membangun basis data pertama kali
> dan **tidak disertakan** di repo. Selama `data/monitoring.sqlite` ada, aplikasi
> berjalan tanpa berkas-berkas tersebut.

## Stack

- **PHP murni** (≥ 8.1, diuji pada 8.4) dengan server bawaan `php -S` — tanpa framework, tanpa build
- **SQLite** sebagai penyimpanan (`data/monitoring.sqlite`, dibuat & di-seed otomatis)
- **Chart.js** (via CDN) untuk grafik — perlu internet saat membuka dashboard
- Tema **gelap/terang** dengan toggle (tersimpan di browser)

## Menjalankan

```bash
./server.sh
```

Lalu buka **http://127.0.0.1:8099**. Tekan `Ctrl+C` untuk berhenti.

Host/port dapat diubah lewat variabel lingkungan:

```bash
PORT=8080 ./server.sh                  # ganti port
HOST=0.0.0.0 PORT=8080 ./server.sh     # akses dari perangkat lain di jaringan (mis. HP petugas)
```

Bila `data/monitoring.sqlite` belum ada **dan** berkas sumber JSON tersedia, basis data
dibuat otomatis: data wilayah & petugas diimpor dari `tableConvert.com_ok842w.json`,
kolom **Jumlah Prelist** di-seed dari `muatan_per_wilayah.json`, dan pemetaan **Tim per
kecamatan** di-seed dari `data/tim_kec.json`. Pemetaan Tim selalu disinkronkan ulang dari
`data/tim_kec.json` setiap aplikasi dijalankan, jadi cukup edit berkas itu untuk
memperbarui Tim.

## Alur penggunaan

1. **Dashboard** (`index.php`) — halaman utama. Tampil rekap **semua kabupaten**,
   lalu bisa di-drill bertingkat:
   `Semua Kabupaten → Kabupaten → Kecamatan → Desa/Kelurahan`.
   Dilengkapi kartu ringkasan, tabel **Rincian per wilayah**, pencarian, ekspor CSV per
   level, dan beberapa grafik (lihat di bawah). Pada level kecamatan, setiap kecamatan
   menampilkan **badge Tim** petugasnya.
2. **Input Progres** (`input.php`) — pilih kabupaten lalu nama pencacah
   (dropdown yang bisa dicari).
3. **Formulir Petugas** (`petugas.php`) — menampilkan seluruh wilayah tugas petugas.
   Setiap baris punya input angka untuk semua kolom progres dan **dapat diperbarui
   berkali-kali**. Penyimpanan membutuhkan **PIN**.

### Kolom progres yang diinput

| Kolom | Keterangan |
|---|---|
| Jumlah Prelist Usaha & Keluarga | Di-seed dari data muatan (dapat diubah petugas) |
| Jumlah Responden Didata | Input manual |
| Jumlah Usaha Tidak Ditemukan | Input manual |
| Jumlah UB Didata | Input manual |
| Jumlah UM Didata | Input manual |
| Jumlah UMK Didata | Input manual |
| Jumlah Usaha dalam Keluarga Didata | Input manual |
| Jumlah Reject | Input manual |
| Jumlah Kunjungan Ulang | Input manual |

> **% Responden** = Jumlah Responden Didata ÷ Jumlah Prelist Usaha & Keluarga.

### Grafik pada dashboard

1. **Capaian Responden per wilayah** — batang `% Responden` per Kabupaten/Kecamatan/Desa
   sesuai level drill saat ini. Pada level kecamatan, label menyertakan nama Tim
   (mis. `WAMENA · Tim 0`).
2. **Capaian Responden per Kabupaten dan per Tim** — batang `% Responden` yang
   diagregasi **per Tim** (lintas kecamatan) pada kabupaten terpilih. Ditampilkan di
   level Semua Kabupaten dan Kabupaten; disembunyikan di level desa atau bila tidak ada
   pemetaan Tim. Sumber rekap: `rekap_tim()` di `lib/helpers.php`, join ke tabel
   `tim_kec`.
3. **Tren Harian (timeseries)** — lihat di bawah.

### Grafik tren harian (timeseries)

Selain grafik batang **% Capaian Responden**, dashboard menampilkan grafik garis
**Tren Harian** untuk scope drill saat ini (Semua Kabupaten / Kabupaten / Kecamatan).
Grafik ini menggambarkan perkembangan **Responden**, **Reject**, dan **Kunjungan
Ulang** pada **5 tanggal penyimpanan terakhir**.

Setiap kali petugas menyimpan progres, dibuat **snapshot harian** (satu record per
wilayah per tanggal — penyimpanan ulang di hari yang sama akan menimpa record hari
itu). Hanya **5 tanggal terakhir** yang disimpan per wilayah. Kolom yang ditampilkan
dan jumlah snapshot dapat diubah lewat `TIMESERIES_COLS` dan `HIST_MAX` di
`lib/config.php`.

## Akses

Penyimpanan progres dilindungi **PIN tunggal** yang berlaku untuk semua petugas.
PIN diatur pada `lib/config.php` (`PETUGAS_PIN`).

## Struktur proyek

```
.
├── server.sh                      # skrip menjalankan server PHP
├── data/
│   ├── monitoring.sqlite          # basis data (ter-seed, ikut di-commit)
│   └── tim_kec.json               # pemetaan Tim per kecamatan (kdkab+kdkec ⇒ Tim)
├── lib/
│   ├── config.php                 # konfigurasi: PIN, daftar kolom, path
│   ├── db.php                     # koneksi, skema, impor & seed data
│   └── helpers.php                # query rekap (termasuk rekap_tim), simpan progres, layout
└── public/                        # dokumen web (document root)
    ├── index.php                  # dashboard (drill-down + grafik)
    ├── input.php                  # pilih kabupaten & petugas
    ├── petugas.php                # formulir input progres
    ├── export.php                 # ekspor CSV
    └── assets/                    # CSS & JavaScript
```

> Berkas sumber `tableConvert.com_ok842w.json` dan `muatan_per_wilayah.json` hanya
> diperlukan untuk membangun basis data dari nol dan tidak disertakan di repo. Letakkan
> di root proyek bila ingin me-seed ulang dari awal (hapus dulu `data/monitoring.sqlite`).

## Konfigurasi (`lib/config.php`)

- `PETUGAS_PIN` — PIN untuk menyimpan progres
- `PROGRES_COLS` — daftar kolom progres (kunci ⇒ label)
- `DASHBOARD_COLS` — kolom yang tampil pada tabel Rincian per wilayah
- `TIMESERIES_COLS`, `HIST_MAX` — kolom & jumlah tanggal grafik tren harian
- `DB_PATH`, `JSON_PATH`, `MUATAN_PATH`, `TIM_PATH` — lokasi basis data & berkas sumber

## Catatan

- Basis data dibuat dari berkas JSON. Jangan menghapus `data/monitoring.sqlite`
  jika sudah berisi input petugas — gunakan ekspor CSV untuk cadangan.
- Pencocokan data muatan ke wilayah memakai 14 digit terakhir kode SLS
  (kab+kec+desa+sls+subsls) agar tetap cocok meski digit provinsi pada sumber
  ada yang tidak konsisten.
- Grafik memerlukan koneksi internet (Chart.js dari CDN).
