# Dokumentasi Deployment — SIMRS RSP Rotinsulu

> Untuk panduan deploy ke Railway langkah demi langkah, lihat `**[RAILWAY.md](RAILWAY.md)**`.
> Dokumen ini menjelaskan **mengapa** aplikasinya seperti ini dan bagaimana
> **mengenalinya saat bekerja**, plus model data dan aturan klinisnya.

## Daftar Isi

1. [Arsitektur](#1-arsitektur)
2. [Menjalankan Secara Lokal](#2-menjalankan-secara-lokal)
3. [Daftar Lengkap Environment Variable](#3-daftar-lengkap-environment-variable)
4. [Ringkasan Model Data](#4-ringkasan-model-data)
5. [Matriks Otorisasi](#5-matriks-otorisasi)
6. [Skala EWS](#6-skala-ews-penting-baca-dulu)
7. [Masalah yang Sudah Diketahui](#7-masalah-yang-sudah-diketahui)

---

## 1. Arsitektur

### 1.1 Tumpukan teknologi

| Lapisan | Teknologi | Versi |
|---|---|---|
| Backend | Laravel | 12.69.3 |
| Bahasa backend | PHP | `^8.2` (lokal 8.2.12, image produksi 8.2) |
| Bridge SPA | Inertia.js | 2.0.28 |
| Framework frontend | Vue | 3.5 |
| Bundler | Vite | 7 |
| CSS | Tailwind CSS | 3.4 (via PostCSS) |
| Database produksi | PostgreSQL | 16 |
| Database lokal | SQLite | bawaan PHP |
| Cache / session | Tabel database (`cache`, `sessions`) | — |
| Web server produksi | nginx + php-fpm (dalam satu container) | nginx 1.27 |

### 1.2_layers dan Request

```
Browser
  |
  |  1. GET /pasien
  |     nginx menerima request (port 8080)
  |       - /build/*  -> dilayani langsung dari disk, PHP tidak pernah jalan
  |       - /up       -> diteruskan ke PHP (healthcheck)
  |       - lainnya   -> try_files $uri $uri/ /index.php
  v
  php-fpm (127.0.0.1:9000, beberapa worker)
  |
  |  2. public/index.php -> bootstrap/app.php
  |     - Middleware web: StartSession, CSRF, HandleInertiaRequests
  |     - routes/web.php memuat routes/pages.*.php lewat glob()
  v
  Controller + Eloquent
  |
  v
  PostgreSQL
  |
  |  3. Balik ke browser:
  |     - Permintaan biasa -> HTML Blade
  |     - Permintaan Inertia (header X-Inertia) -> JSON + tag <div id="app">
  v
  Vue 3 mengambil props dan merender
```

### 1.3 Mengapa `pasien` bukan SPA

Halaman `pasien` dirender sebagai **Blade**, bukan Inertia. Alasannya ada di
`docs/FRONTEND_CONTRACT.md`: daftar pasien adalah halaman dengan banyak kartu
yang masing-masing melakukan *full page load* ke halaman profil. Menjalankannya
sebagai SPA tidak memberi keuntungan apa pun, dan justru menambah satu bundel
JavaScript yang harus diunduh sebelum pengguna melihat data.

Enam halaman lainnya (profil, cppt, penunjang, farmasi, observasi, bundles)
adalah SPA Inertia. Header `X-Inertia` yang membedakan keduanya, dan
`bootstrap/app.php` sudah menangani halaman error 403/404 untuk kedua jalur
(klien Inertia melakukan *hard reload* saat menerima respons non-Inertia 4xx/5xx,
jadi tidak perlu komponen Vue error terpisah).

### 1.4 Bentuk image produksi

```
Image: ~180 MB, 3 tahap build
  Stage 1  assets   node:22-alpine  -> public/build/**  (asset hash)
  Stage 2  vendor   composer:2      -> vendor/          (classmap-authoritative)
  Stage 3  runtime  php:8.2-fpm-alpine + nginx          <- satu-satunya yang dikirim

Isi runtime:
  /var/www/html/app|config|database|routes|resources|storage
  /var/www/html/public/            <- termasuk public/build dari Tahap 1
  /var/www/html/vendor/            <- dari Tahap 2, teroptimasi
  /usr/local/etc/php/conf.d/zz-app.ini
  /usr/local/etc/php-fpm.d/zz-app.conf
  /etc/nginx/nginx.conf
  /usr/local/bin/entrypoint.sh

Dijalankan sebagai: www-data (non-root), tanpa alat build Node maupun Composer.
```

---

## 2. Menjalankan Secara Lokal

### 2.1 Prasyarat

| Kebutuhan | Versi | Cara mengecek |
|---|---|---|
| PHP | 8.2 atau lebih baru | `php -v` |
| Composer | 2.x | `composer -V` |
| Node.js | 20.19+ atau 22.12+ (Vite 7) | `node -v` |
| npm | 10+ | `npm -v` |
| ekstensi PHP | `pdo_sqlite`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `curl`, `zip`, `bcmath` | `php -m` |

Di **Windows dengan XAMPP**, PHP mungkin belum ada di `PATH`. Aktifkan dulu
sebelum menjalankan perintah apa pun:

```powershell
$env:PATH = "C:\xampp\php;$env:PATH"
```

### 2.2 Opsi A — SQLite (paling cepat, Recommended untuk development)

```bash
cd backend

composer install
npm install

# .env
cp .env.example .env          # Linux/macOS
copy .env.example .env        # Windows

php artisan key:generate

# Buat file database lalu jalankan migrasi + data demo
touch database/database.sqlite   # Windows: New-Item database/database.sqlite -ItemType File
php artisan migrate
php artisan db:seed

# Build aset untuk produksi, ATAU jalankan Vite dalam mode dev
npm run build                   # build sekali
php artisan serve               # http://localhost:8000

# Alternatif: hot reload selama pengembangan
npm run dev                     # terminal terpisah, Vite di port 5173
```

### 2.3 Opsi B — PostgreSQL dengan Docker (paritas dengan produksi)

Karena `docker` mungkin tidak tersedia, opsional. Jalur ini **sangat
dibkomendasikan** sebelum deploy, karena memakai image yang sama persis dengan
yang akan jalan di Railway.

```bash
cd backend

# Build image (sekali, butuh beberapa menit)
docker compose -f docker-compose.pgsql.yml up -d --build

# Migrasi
docker compose -f docker-compose.pgsql.yml run --rm app php artisan migrate --force

# Data demo
docker compose -f docker-compose.pgsql.yml run --rm app php artisan db:seed --force

# Cek skema sudah benar
docker compose -f docker-compose.pgsql.yml exec app php artisan migrate:status

# Buka di browser
#   http://localhost:8080
```

Utilitas:

```bash
# Masuk ke psql tanpa memasang PostgreSQL di host
docker compose -f docker-compose.pgsql.yml --profile tools run --rm db-shell

# Lihat log container aplikasi
docker compose -f docker-compose.pgsql.yml logs -f app

# Hentikan (data TETAP ada karena disimpan di named volume)
docker compose -f docker-compose.pgsql.yml down

# Hentikan dan HAPUS semua data
docker compose -f docker-compose.pgsql.yml down -v
```

### 2.4 Opsi C — stack SQLite penuh dengan Docker

Untuk menguji image PHP tanpa PostgreSQL. **Tidak direkomendasikan** untuk
meniru produksi.

```bash
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
# http://localhost:8000
```

### 2.5 Perintah yang sering dipakai

```bash
php artisan migrate:status           # status migrasi
php artisan migrate:fresh --seed    # reset total + seed ulang
php artisan config:clear            # WAJIB setelah mengubah .env
php artisan optimize:clear          # bersihkan semua cache
php artisan route:list               # lihat semua rute
php artisan about                   # ringkasan versi & konfigurasi
npm run build                       # build aset produksi
```

> **`config:clear` setelah mengubah `.env`.** Kalau tidak, perubahan tidak
> akan terbaca karena `config:cache` yang lama masih dipakai. Ini penyebab
> paling umum dari \"saya sudah ubah `.env` tapi tidak ada efeknya\".

---

## 3. Daftar Lengkap Environment Variable

Semua variable yang dibaca aplikasi, lengkap dengan sumber dan default-nya.
Daftar canonical ada di **`.env.production.example`**, yang berisi komentar
penjelasan untuk setiap baris. Ringkasnya:

### 3.1 Inti aplikasi

| Nama | Default | Produksi | Catatan |
|---|---|---|---|
| `APP_NAME` | `Laravel` | `SIMRS RSP Rotinsulu` | Dipakai untuk judul halaman dan nama cookie. |
| `APP_ENV` | `production` | `production` | |
| `APP_KEY` | — | **wajib diisi** | `php artisan key:generate --show`. Mengganti = semua user logout. |
| `APP_DEBUG` | `true` | **`false`** | `true` membocorkan stack trace. |
| `APP_URL` | `http://localhost` | domain Railway | Tanpa garis miring di akhir. Penting untuk CSRF/Inertia. |
| `APP_LOCALE` | `en` | `en` | UI ditulis manual dalam Bahasa Indonesia. |
| `BCRYPT_ROUNDS` | `12` | `12` | Hash password. |

### 3.2 Database

| Nama | Produksi | Catatan |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | Wajib. Default `sqlite` akan membuat DB kosong di dalam container. |
| `DATABASE_URL` | disuntikkan Railway | **Format pilihan.** Dibaca `config/database.php`. |
| `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` `DB_PASSWORD` | — | Alternatif terpisah, untuk Docker lokal. Diabaikan kalau `DATABASE_URL` terisi. |
| `DB_SSLMODE` | `prefer` | Railway sering menimpanya lewat query string di `DATABASE_URL`. |

### 3.3 Session, cache, queue, broadcast, filesystem

| Nama | Produksi | Catatan |
|---|---|---|
| `SESSION_DRIVER` | `database` | **Wajib.** `file` hilang tiap container restart. |
| `SESSION_LIFETIME` | `120` | Menit. |
| `SESSION_SECURE_COOKIE` | `true` | Untuk HTTPS. |
| `SESSION_SAME_SITE` | `lax` | |
| `CACHE_STORE` | `database` | **Wajib.** |
| `QUEUE_CONNECTION` | `sync` | Tidak ada job di aplikasi ini. |
| `BROADCAST_CONNECTION` | `log` | Tidak ada broadcasting. |
| `FILESYSTEM_DISK` | `local` | Tidak ada upload file. |

### 3.4 Logging

| Nama | Produksi | Catatan |
|---|---|---|
| `LOG_CHANNEL` | `stderr` | **Wajib.** Railway hanya menangkap stdout/stderr. |
| `LOG_LEVEL` | `info` | |

### 3.5 Toggle runtime (`docker/entrypoint.sh`)

| Nama | Default | Fungsi |
|---|---|---|
| `RUN_MIGRATIONS` | `false` | `true` = `migrate --force` tiap container start. |
| `SEED_DEMO_DATA` | `false` | `true` = `db:seed --force` tiap start. **Jangan di produksi.** |
| `CACHE_CONFIG` / `CACHE_ROUTES` / `CACHE_VIEWS` | `true` | Cache artefak Laravel saat boot. |
| `DB_WAIT_SECONDS` / `DB_WAIT_INTERVAL` | `60` / `2` | Batas menunggu PostgreSQL siap. |
| `PHP_CLI_SERVER_WORKERS` | `4` | Hanya jalur Nixpacks. Diabaikan di jalur Docker. |

---

## 4. Ringkasan Model Data

15 migrasi, 13 model Eloquent. Angka di kolom \"Isi\" berasal dari seeder.

### 4.1 Inti

| Tabel | Model | Isi | Kunci |
|---|---|---|---|
| `users` | `User` | 3 petugas | `username` |
| `patients` | `Patient` | 6 pasien | `patient_id` |
| `encounters` | `Encounter` | 6 episode perawatan | `encounter_id` (string, mis. `enc-159853-icu-20260906`) |

### 4.2 Dokumentasi klinis per episode

| Tabel | Model | Isi |
|---|---|---|
| `asmeds` | `Asmed` | Anamnesis dan pemeriksaan fisik awal per episode |
| `diagnoses` | `Diagnosis` | ICD-coded |
| `procedures` | `Procedure` | Tindakan |
| `nursing_cares` | `NursingCare` | Asuhan keperawatan per shift |
| `medical_notes` | `MedicalNote` | CPPT |

### 4.3 Observasi (inti aplikasi)

| Tabel | Model | Isi |
|---|---|---|
| `observations` | `Observation` | **60** observasi EWS |
| `observation_medications` | `ObservationMedication` | Koreksi pemberian obat per observasi |
| `observation_bundle_answers` | `ObservationBundleAnswer` | Jawaban bundle pencegahan infections |
| `invasive_devices` | `InvasiveDevice` | Perangkat invasif per episode |

### 4.4 Penunjang

| Tabel | Model | Isi |
|---|---|---|
| `support_results` | `SupportResult` | 118 hasil lab, darah, mikroba, radiologi |
| `abg_results` | `AbgResult` | 15 hasil analisa gas darah |

### 4.5 Tabel sistem Laravel

`migrations`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.
Ketiga yang pertama wajib ada di produksi karena `SESSION_DRIVER=database` dan
`CACHE_STORE=database`.

### 4.6 Konstanta klinis yang BUKAN di database

Hal ini penting untuk dipahami saat menambah fitur baru:

| Lokasi | Isi | Alasan tidak jadi tabel |
|---|---|---|
| `config/ews.php` | Tabel skor EWS 6 parameter + ambang eskalasi | Konstanta klinis, tidak pernah berubah per pasien. |
| `config/hai.php` | Katalog bundle dan perangkat invasif | Sama. |
| `config/formularium.php` | Formularium obat | Sama. |

Tidak ada tabel rujukan `ClinicalReferenceSeeder`, dan memang tidak
diperlukan. Membuatnya hanya akan menduplikasi konfigurasi yang sudah ada.

---

## 5. Matriks Otorisasi

Tiga peran, dari `App\Enums\UserRole`.

| Peran | Username demo | Password |
|---|---|---|
| Perawat | `perawat` | `perawat123` |
| Bidan | `bidan` | `bidan123` |
| Dokter | `dokter` | `dokter123` |

### 5.1 Matriks rute

| Halaman | URL | Render | Middleware | Perawat | Bidan | Dokter |
|---|---|---|---|:--:|:--:|:--:|
| Login | `/login` | Blade | `guest` | — | — | — |
| Daftar pasien | `/pasien` | Blade | `auth` | ya | ya | ya |
| Profil pasien | `/encounters/{id}/profil` | Inertia | `auth` | ya | ya | ya |
| CPPT | `/encounters/{id}/cppt` | Inertia | `auth`, `role:perawat,bidan,dokter` | ya | ya | ya |
| Penunjang | `/encounters/{id}/penunjang` | Inertia | `auth`, `role:perawat,bidan,dokter` | ya | ya | ya |
| Farmasi | `/encounters/{id}/farmasi` | Inertia | `auth`, `role:perawat,bidan,dokter` | ya | ya | ya |
| Observasi | `/encounters/{id}/observasi` | Inertia | `auth`, `role:perawat,bidan,dokter` | ya | ya | ya |
| Bundles | `/encounters/{id}/bundles` | Inertia | `auth`, `role:perawat,bidan,dokter` | ya | ya | ya |

**Semua peran punya akses ke semua halaman.** Matriks ini seragam karena belum
ada perbedaan akses per peran yang disepakati; middleware `role:` sudah
dipasang dan siap membatasi modul tertentu kapan saja: cukup menambahkan nama
peran, misalnya `->middleware(['auth', 'role:dokter'])`.

### 5.2 Perilaku lain

- **Belum login** saat membuka halaman mana pun yang butuh `auth` -> **redirect ke
  `/login`**, dan URL tujuan disimpan di session supaya setelah login pengguna
  dikembalikan ke halaman yang tadi.
- **Role tidak sesuai** → **403** dengan pesan Bahasa Indonesia
  \"Anda tidak memiliki akses ke modul ini.\" Halaman Blade, bukan komponen Vue.
- **Halaman tidak ada** → **404** dengan pesan \"Halaman yang Anda cari tidak
  ditemukan.\"
- **Rate limit login** → `throttle:login`, ditambah `limit_req` 10 request per
  menit per IP di level nginx.
- `{encounter}` di URL adalah **string** `encounters.encounter_id`, bukan `id`
  numerik.

---

## 6. Skala EWS

> ### ⚠️ BACA DULU: INI BUKAN MEWS
>
> Skala di aplikasi ini adalah **British Early Warning Scale 4 tingkat** —
> **BUKAN** MEWS 3-tingkat yang lebih umum dikenal.
>
> Mengubahnya ke MEWS standar akan langsung merusak 60 baris observasi yang
> sudah di-seed, karena `ews_total` dan `ews_risk` di setiap baris dihitung dari
> tabel di `config/ews.php`.

### 6.1 Apa bedanya dari MEWS

| | Aplikasi ini | MEWS konvensional |
|---|---|---|
| Jumlah parameter | **6** | 5 |
| Kesadaran | **parameter tersendiri** | tersirat di parameter lain |
| Rentang skor | semua parameter punya 0-3 penuh | skor 3 hanya mungkin di satu parameter |
| Jumlah tingkat | **4** | 3 |
| Skor maksimum | **18** | 14 |

### 6.2 Parameter dan skor

| Parameter | Kolom | 0 | 1 | 2 | 3 |
|---|---|---|---|---|---|
| Respirasi (x/mnt) | `rr` | 12-20 | 9-11 | 0-8, 21-24 | 25+ |
| Nadi (x/mnt) | `hr` | 51-90 | 41-50, 91-110 | 0-40, 111-130 | 131+ |
| Tekanan sistolik (mmHg) | `sys` | 101-179 | 81-100, 180-220 | 0-70, 221+ | — |
| Saturasi oksigen (%) | `spo2` | 95-100 | 93-94 | 91-92 | 0-90 |
| Suhu tubuh (°C) | `suhu` | 36.1-38.0 | 35.1-36.0, 38.1-38.5 | 0-35.0, 38.6-41 | 41.1+ |
| Kesadaran (AVPU) | `kesadaran` | Alert, DPO | Voice | Pain | Unresponsive |

> Perhatikan kolom skor pada tekanan sistolik: **tidak ada skor 3**. Band
> tertinggi di situ adalah 2 (0-70 dan 221+). Skor 3 pada denyut jantung dan
> pernapasan pun tidak selalu ada di semua versi. Inilah yang membuat tabel ini
> **harus** dibaca langsung dari `config/ews.php`, bukan dari ingatan.

### 6.3 Ambang eskalasi

| Total skor | Tingkat | Arti |
|---|---|---|
| 0 - 2 | **low** | Monitor rutin |
| 3 - 4 | **medium** | Perlu perhatian lebih |
| 5 - 6 | **high** | Perlu tindakan segera |
| 7 - 18 | **emergency** | **Nanggungan.** Skor maksimum yang mungkin adalah 18. |

### 6.4 Aturan yang harus dipatuhi

1. **Jangan mengganti `config/ews.php` ke MEWS tanpa keputusan tim klinis.**
   Kalau sampai diganti, 60 observasi hasil seed harus di-*reseed* supaya
   `ews_total` dan `ews_risk`-nya tetap konsisten.
2. **Nilai `level` di `config/ews.php` harus sinkron dengan
   `App\Enums\EwsRiskLevel`.** Kalau hanya salah satu yang diubah, halaman
   bisa gagal saat membaca baris karena cast enum melempar exception.
3. **Seeder memeriksa konsistensi ini.** `DatabaseSeeder::assertSeedIntegrity()`
   akan melempar exception yang menyebutkan daftar lengkapnya kalau `ews_total`
   tidak sama dengan jumlah skor per parameter, kalau `ews_risk` di luar
   rentang, atau kalau skor melewati 18. Itu disengaja:
   lebih baik seeding gagal dengan pesan jelas daripada menyimpan data klinis
   yang salah.

---

## 7. Masalah yang Sudah Diketahui

Temuan selama menyiapkan konfigurasi deploy. Semuanya sudah ditangani kecuali
butir 1, 2, dan 3.

### 7.1 `BCRYPT_ROUNDS` tidak dibaca

Tidak ada berkas `config/hashing.php`, jadi `hashing.bcrypt.rounds` tidak
terbaca dan Laravel memakai default 12. Variabel ini di `.env` **tidak
berdampak**. Tidak berbahaya, tapi jangan menganggap mengubah nilai ini
mengubah hash strength.

### 7.2 `BROADCAST_CONNECTION` tidak dibaca

Tidak ada `config/broadcasting.php` juga. Tidak berbahaya karena aplikasi ini
tidak memakai broadcasting sama sekali; variabelnya hanya untuk kelengkapan.

### 7.3 `php artisan serve` tidak berfungsi di proyek ini

Laravel 11+ menghapus `server.php` dari proyek skeleton, dan proyek ini tidak
memilikinya. Jadi `php artisan serve` akan gagal mencari router-nya. Untuk
development, jalankan `php -S` dengan router sendiri, atau pakai jalur Docker.

### 7.4 `route:cache` dan loader `routes/pages.*.php`

`routes/web.php` memuat berkas `routes/pages.*.php` dengan `glob()`. Artinya
hasil `route:cache` adalah **snapshot** saat cache dibuat. Menambah rute baru
butuh **deploy image baru**, bukan sekadar restart container. Entrypoint
menangani `route:cache` yang gagal tanpa menjatuhkan aplikasi, jadi
kenyamanan ini tidak isntinya hal yang cleaned.

### 7.5 `phpredis` tidak dipasang

`REDIS_*` ada di `.env` sebagai dokumentasi, tapi image produksi sengaja tidak
memasang ekstensi `phpredis`, karena aplikasi ini memakai database untuk session
dan cache. Kalau nanti pindah ke Redis, ada satu langkah tambahan: pasang
`phpredis` di `Dockerfile` pada tahap runtime, dan tambahkan plugin Redis di
Railway.
