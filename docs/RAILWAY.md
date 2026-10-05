# Panduan Deploy ke Railway (Laravel 12 + PostgreSQL)

Dokumen ini untuk developer **baru** yang belum pernah deploy aplikasi Laravel
ke Railway. Setiap langkah disertai alasannya, bukan hanya perintahnya.

> Dokumen pendukung: [DEPLOYMENT.md](DEPLOYMENT.md) (arsitektur, konfigurasi
> lokal, model data) dan [../README.md](../README.md).

---

## Daftar Isi

1. [Peta Mental: Apa yang Terjadi Saat Deploy](#1-peta-mental)
2. [Pilih Jalur Build: Docker atau Nixpacks](#2-pilih-jalur-build)
3. [Membuat Project di Railway](#3-membuat-project-di-railway)
4. [Menambah Plugin PostgreSQL](#4-menambah-plugin-postgresql)
5. [Variable Service yang Wajib](#5-variable-service-yang-wajib)
6. [Deploy Pertama dan Membaca Log](#6-deploy-dan-membaca-log)
7. [Menjalankan Migrasi](#7-menjalankan-migrasi)
8. [Data Demo:IBLE dan Tidak](#8-seeding-data-demo)
9. [Domain Kustom dan HTTPS](#9-domain-kustom-dan-https)
10. [Mengatur Ulang dan Menonaktifkan](#10-mengatur-ulang-dan-bypass)
11. [Tabel Troubleshooting](#11-tabel-troubleshooting)
12. [Checklist Harian dan Saat Deploy](#12-checklist)

---

## 1. Peta Mental

Sebelum menyentuh Railway, pahami dulu apa yang terjadi di belakang layar.
SaaS seperti Railway bekerja seperti ini:

```
GitHub (kode)  ──push──▶  Railway Build  ──▶  Railway Deploy  ──▶  URL publik
                                 │                     │
                                 │                     ├─▶ Container #1 (aplikasi)
                                 │                     └─▶ Container #2 (PostgreSQL)
                                 │
                                 └─-build image: compile aset + install dependency
```

Tiga hal penting yang sering tidak realizes orang:

1. **Build dan Deploy itu dua tahap berbeda.**
   - *Build* = mengubah kode menjadi sebuah *image* (seperti file `.iso`).
     Di tahap ini `npm ci`, `npm run build`, dan `composer install` dijalankan.
     Tidak ada kode aplikasi yang dieksekusi.
   - *Deploy* = menyalakan image itu menjadi container yang berjalan, lalu
     menunggu sampai healthcheck berhasil.

2. **Variable environment hanya dibaca saat container BERJALAN**, bukan saat
   build. Mengubah variable di dashboard **tidak langsung berefek**; kamu harus
   memicu deploy ulang. Ini karena `php artisan config:cache` membekukan seluruh
   nilai variable ke dalam satu file saat container start.

3. **PostgreSQL di Railway adalah service terpisah**, bukan bagian dari aplikasi.
   Keduanya harus berada di project yang sama, dan pluginnya harus terpasang di
   service yang SAMA dengan service aplikasi. Salah pasang adalah penyebab
   kegagalan nomor satu pada tutorial Railway.

---

## 2. Pilih Jalur Build

Repo ini menyediakan **dua** jalur build. Keduanya menghasilkan aplikasi yang
sama, tapi dengan kekuatan dan kelemahan yang berbeda.

| | **Docker (disarankan)** | **Nixpacks** |
|---|---|---|
| Berkas pengatur | `Dockerfile` + `railway.json` | `nixpacks.toml` + `Procfile` |
| Web server | nginx + php-fpm (banyak worker) | PHP built-in server (`php -S`) |
| Beban CPU saat ramai | Tinggi,/~ masih bureau | Tinggi, sering antre |
| Ekstensi PHP | Dikunci & diverifikasi saat build | Dipilih heuristik oleh Nixpacks |
| Ukuran image | ~180 MB | ~350 MB |
| Build image | 3-5 menit | 2-4 menit |
| Kontrol atas image | Penuh | Sebagian |

### Kenapa Docker jadi default

Alasannya teknis, bukan preferensi:

- **`php artisan serve` dan PHP built-in server bersifat SATU-THREADED.** Satu
  permintaan yang lambat akan memblokir semua permintaan lain. Untuk aplikasi
  klinis yang dipakai beberapa petugas sekaligus, ini tidak bisa diterima.
  Image produksi memakai **php-fpm** yang menjalankan beberapa worker sekaligus.
- **Ekstensi PHP diverifikasi saat build.** `Dockerfile` punya gerbang yang
  memaksa build GAGAL kalau `pdo_pgsql` tidak termuat. Pada jalur Nixpacks,
  ekstensi bisa hilang tanpa(build tetap hijau, lalu semua query gagal dengan
  `could not find driver` saat aplikasi sudahlive di produksi.

### Kapan memilih Nixpacks

Hanya kalau kamu punya alasan konkret:

- Tidak bisa memasang Docker di komputer atau di CI.
- Ingin’image paling kecil tanpa perlu tuning nginx.
- Ingin Railway yang menentukan versi PHP-nya.

### ⚠️ Jangan pakai dua-duanya sekaligus

`railway.json` berisi `build.builder = "DOCKERFILE"`. Selama berkas itu ada,
**Railway mengabaikan `nixpacks.toml` dan `Procfile` sepenuhnya.** Ini adalah
penyebab paling sering dari "saya sudah mengubah Procfile tapi tidak ada
perubahan sama sekali".

Untuk beralih ke Nixpacks, **hapus `railway.json`**, atau ubah
`build.builder` menjadi `"NIXPACKS"` lewat dashboard.

---

## 3. Membuat Project di Railway

### 3.1 Metode A — dari GitHub (disarankan)

1. Login ke [railway.app](https://railway.app).
2. Klik **New Project** → **Deploy from GitHub repo**.
3. Hubungkan akun GitHub, lalu pilih repository aplikasi ini.
4. Railway otomatis membuat service bernama seperti `stitch-desain-observasi-ews`.
5. Railway langsung memulai deploy pertama. **Deploy ini PASTI gagal** karena
   `APP_KEY` belum diisi dan database belum ada. Itu wajar, dan justru berguna:
   kamu bisa melihat bentuk log-nya sekarang, sebelum ada perubahan yang sebenarnya.

> **Penting:** project Inertia/Laravel harus berada di **root repository**,
> yaitu folder yang berisi `artisan`, `composer.json`, dan `Dockerfile`. Kalau
> kodemu berada di subfolder `backend/`, atur **Root Directory** di
> `Settings` → `Build` → `Root Directory` menjadi `backend`.

### 3.2 Metode B — deploy dari CLI

Berguna kalau kamu tidak ingin menghubungkan GitHub, atau sedang menguji
deploy sebelum pushing.

```bash
# 1. Pasang CLI
npm i -g @railway/cli

# 2. Login
railway login

# 3. Dari folder backend/ (folder yang berisi Dockerfile)
railway init

# 4. Hubungkan ke project
railway link

# 5. Pilih service yang mau dipakai
railway service

# 6. Deploy kode yang ada di direktori sekarang
railway up
```

`railway init` membuat project kosong, jadi setelah itu **kamu tetap perlu
menambah plugin PostgreSQL** seperti di bagian berikutnya.

> Bisa juga lewat dashboard: **New Project** → **Empty Project**, lalu jalankan
> perintah `railway link` yang tertera di halaman project tersebut.

---

## 4. Menambah Plugin PostgreSQL

**INI LANGKAH YANG PALING SERING TERLEWATI.** Tanpa ini, aplikasi tidak akan
bisa terhubung ke database sama sekali.

1. Buka project kamu di dashboard Railway.
2. Klik **+ New** → **Add Plugin** → cari **PostgreSQL** → **Add**.
3. Railway membuat **service baru** terpisah bernama `PostgreSQL`.
4. Tunggu sampai statusnya berubah dari *Deploying* menjadi **Active**.

### Yang disuntikkan Railway ke service aplikasi

Begitu plugin terpasang, Railway otomatis menyuntikkan variabel berikut ke
**service aplikasi** (bukan ke service PostgreSQL):

| Variable | Contoh | Keterangan |
|---|---|---|
| `DATABASE_URL` | `postgresql://simrs:xY9kLm2pQ@pg.railway.internal:5432/railway` | Dipakai oleh Laravel untuk menghubungkan ke DB. **Ini yang paling penting.** |
| `PGHOST` | `pg.railway.internal` | Host database. |
| `PGPORT` | `5432` | Port database. |
| `PGDATABASE` | `railway` | Nama database. |
| `PGUSER` | `simrs` | Username database. |
| `PGPASSWORD` | `xY9kLm2pQ` | Password database. |
| `PGSSLMODE` | `prefer` atau `require` | Mode SSL. |

### Mengapa `DATABASE_URL` sangat penting

Aplikasi ini membaca `DATABASE_URL` (lihat `config/database.php`), bukan
`DB_HOST`/`DB_PORT` secara terpisah. `DATABASE_URL` berisi semuanya sekaligus
dalam satu string, dan Laravel menguraikannya sendiri secara otomatis.

Kalau `DATABASE_URL` tidak ada, Laravel akan jatuh ke `DB_HOST=127.0.0.1` — yaitu
menghubungkan diri ke localhost container, yang tidak pernah ada datanya. Error
yang muncul adalah:

```
SQLSTATE[08006] [7] could not translate host name "127.0.0.1"
```

### Kalau plugin dipasang di project yang berbeda

`DATABASE_URL` hanya otomatis tersuntik ke service di **project yang sama**.
Kalau kamu tidak melihat `DATABASE_URL` di daftar variable service aplikasi:

1. Pastikan plugin-nya ada di **project yang sama**.
2. Pastikan tidak ada salah ketik di nama service.
3. Deploy ulang service aplikasi setelah plugin terpasang. Penambahan plugin
   **tidak** otomatis memicu deploy ulang pada aplikasi.

---

## 5. Variable Service yang Wajib

Buka **service aplikasi** → tab **Variables** → **Shared Variables**.

> ⚠️ **Jangan pernah menempelkan isi `.env.production` ke sini secara utuh.**
> Baris yang berawalan `#` di berkas itu **tidak akan diimpor** oleh Railway, dan
> menyalin-paste manual berisiko kelewatan satu baris. Gunakan daftar di bawah.

### 5.1 Yang wajib diisi manual

| # | Nama | Contoh | Wajib? | Kalau hilang, apa yang rusak |
|---|---|---|---|---|
| 1 | `APP_KEY` | `base64:AbCdEf...==` | **YA** | **Semua** halaman 500 dengan `MissingAppKeyException`. Cookie session tidak bisa dienkripsi, jadi tidak ada yang bisa login. Ini penyebab kegagalan nomor satu. |
| 2 | `APP_ENV` | `production` | **YA** | Perilaku app berubah; `production` mengaktifkan pesan error yang disamar. Tidak fatal, tapi salah. |
| 3 | `APP_DEBUG` | `false` | **YA** | `true` membocorkan stack trace, nama kolom database, dan nilai environment ke setiap pengunjung. Kalau `true` di produksi, anggap ini insiden keamanan. |
| 4 | `APP_URL` | `https://simrs-ews.up.railway.app` | **YA** | Link setelah login melompat ke `localhost`; Inertia dan proteksi CSRF gagal mencocokkan Origin, sehingga muncul **419 Token Mismatch**. |
| 5 | `LOG_CHANNEL` | `stderr` | **YA** | Log ditulis ke `storage/logs/laravel.log` di dalam container. Error **tampak tidak ada** di dashboard Railway, dan log hilang setiap container di-restart. |
| 6 | `LOG_LEVEL` | `info` | **YA** | Log terlalu banyak (`debug`) atau terlalu sedikit (`error`). `debug` di produksi-CEKLOP boros kuota log. |
| 7 | `DB_CONNECTION` | `pgsql` | **YA** | Default-nya `sqlite`. Aplikasi akan membuat file database kosong di dalam container, dan **semua data hilang setiap deploy**. |
| 8 | `SESSION_DRIVER` | `database` | **YA** | Default `file`; session tersimpan di dalam container. Setiap restart = semua user logout, dan dengan 2 replica user logout terus-menerus. |
| 9 | `CACHE_STORE` | `database` | **YA** | Sama seperti session: cache `file` hilang tiap restart. |
| 10 | `QUEUE_CONNECTION` | `sync` | **YA** | Tidak ada job di aplikasi ini, jadi `sync` sudah benar. Yang penting agar tidak ada worker queue sia-sia. |
| 11 | `BROADCAST_CONNECTION` | `log` | **YA** | Ditulis demi kelengkapan. Tidak ada broadcasting di aplikasi ini. |
| 12 | `FILESYSTEM_DISK` | `local` | **YA** | Idem. Tidak ada upload file. |

### 5.2 Yang disuntikkan otomatis oleh Railway

| Nama | Sumber | Wajib? | Kalau hilang |
|---|---|---|---|
| `DATABASE_URL` | Plugin PostgreSQL | **YA** | `could not translate host name` — aplikasi tidak bisa konek DB sama sekali. |
| `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`, `PGSSLMODE` | Plugin PostgreSQL | Tidak | Tidak dipakai langsung oleh Laravel, tapi pertahankan agar tooling (psql, dump) tetap bisa jalan. |

### 5.3 Optional (biarkan kosong / default)

| Nama | Default | Kapan perlu diisi |
|---|---|---|
| `SESSION_SECURE_COOKIE` | `true` di contoh | **Sebaiknya `true`**. Railway menyediakan HTTPS, jadi cookie tidak perlu terkirim lewat HTTP. |
| `SESSION_SAME_SITE` | `lax` | Biarkan `lax`. |
| `BCRYPT_ROUNDS` | `12` | Biarkan. |
| `MAIL_MAILER` | `log` | Ubah ke `smtp` hanya kalau memang perlu kirim email sungguhan. |
| `RUN_MIGRATIONS` | `false` | Set `true` hanya kalau tidak bisa menjalankan `railway run`. **Baca peringatan di bagian 7.** |
| `SEED_DEMO_DATA` | `false` | **Jangan** set `true` di produksi. |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | `en` | Biarkan. Teks UI ditulis manual dalam Bahasa Indonesia. |
| `REDIS_*`, `AWS_*`, `MEMCACHED_*` | — | Tidak dipakai aplikasi ini. **Jangan isi**; kredensial yang tidak perlu adalah kredensial yang bisa bocor. |

### 5.4 Cara membuat `APP_KEY`

**Jalankan di komputer lokal**, bukan di Railway:

```bash
cd backend
php artisan key:generate --show
```

Contoh keluaran:

```
base64:AbCdEf0123456789+/xyzABCDEFGHIJKLMNOPQRSTUVWXYZ=
```

Salin **persis** termasuk awalan `base64:`, lalu tempel di Railway.

> **Jangan pernah** menjalankan `php artisan key:generate` di Railway. Perintah itu
> membangkitkan kunci BARU. Kalau kuncinya berubah, **semua user langsung
> logout** karena cookie session lama tidak bisa didekripsi lagi.

---

## 6. Deploy dan Membaca Log

### 6.1 Memicu deploy

- **Dari GitHub:** push ke branch yang terhubung.
  ```bash
  git add -A
  git commit -m "docs: panduan deploy Railway"
  git push origin main
  ```
- **Dari CLI:** `railway up`

### 6.2 Membaca Build Logs

Klik deploy terbaru → **Build Logs**. Urutan yang sehat:

```
=== Stage 1/3 - assets ===
npm ci --no-audit --no-fund
added 1042 packages
> vite build
vite v7.0.7 building for production...
✓ 2847 modules transformed.
public/build/manifest.json          <-- WAJIB ada
=== Stage 2/3 - vendor ===
composer install --no-dev --no-scripts --no-autoloader
Generating optimized autoload files
classmap-authoritative
=== Stage 3/3 - runtime ===
[+] Installing packages...
[+] Build gates passed                  <-- WAJIB ada
```

**Tanda kegagalan:**

| Pola di Build Log | Artinya |
|---|---|
| `could not find driver` | Build tidak perlu database, jadi ini pasti dari `composer install` atau `php artisan`. Periksa versi PHP. |
| `FATAL: pdo_pgsql tidak termuat` | Gerbang build di Dockerfile bekerja. Perbaiki ekstensi, jangan di-bypass. |
| `FATAL: public/build/manifest.json tidak dihasilkan` | `npm run build` gagal. Buka log Vite di baris-baris sebelumnya. |
| `npm ERR! code E401` / `E403` | `package-lock.json` tidak sinkron dengan `package.json`. Jalankan `npm install` lalu commit `package-lock.json`. |
| `Your requirements could not be resolved` | `composer.lock` tidak sinkron dengan `composer.json`. Jalankan `composer update` lalu commit `composer.lock`. |

### 6.3 Membaca Deploy Logs

Setelah image jadi, Railway menyalakan container. Log ini berasal dari
`docker/entrypoint.sh`, dan urutannya bisa jadi alat diagnosis paling berguna:

```
[entrypoint] non-root: storage/ + bootstrap/cache/ sudah writable, chown dilewati
[entrypoint] php artisan config:cache
Configuration cached successfully.
[entrypoint] php artisan route:cache
Route cache cleared successfully.
[entrypoint] route cache OK
[entrypoint] php artisan view:cache
Compiled views cleared successfully.
[entrypoint] menjalankan: php artisan migrate --force
[entrypoint] migrate SELESAI
[entrypoint] menjalankan php-fpm (background)
[entrypoint] php-fpm siap di 127.0.0.1:9000
[entrypoint] nginx foreground di port 8080, container siap
```

Lalu, untuk setiap request HTTP:

```
127.0.0.1 - - [30/Sep/2026:08:45:12 +0000] "GET /login HTTP/1.1" 200 4821 "-" "Mozilla/5.0" rt=0.031 412KB
```

| Pola di Deploy Log | Artinya |
|---|---|
| `[entrypoint] FATAL: APP_KEY kosong` | `APP_KEY` belum diset. Persis yangroni disection 5. |
| `[entrypoint] FATAL: PostgreSQL tidak siap dalam 60s` | `DATABASE_URL` salah, atau plugin PostgreSQL belum Active. |
| `[entrypoint] WARN: route:cache gagal` | **Tidak fatal.** App tetap jalan; rute dibaca ulang tiap request. |
| `WARN: php-fpm belum siap di 30s` | Marinaetzend, tapi nginx akan mencoba. Kalau 502 terus, periksa RAM instance. |
| `PHP Warning: ... json.so` | Ada `docker-php-ext-enable json` yang keliru. `json` selalu built-in di PHP 8. |
| Tidak ada request sama sekali | Healthcheck `railway.json` gagal. Buka tab **Healthcheck** pada deploy tersebut. |

### 6.4 Tanda aplikasi benar-benar sehat

1. Buka `https://<domain-railway-kamu>/up` → harusnya muncul `200` dengan
   halaman kosong-putih. Kalau 200, PHP + nginx + routing sudah benar.
2. Buka `https://<domain-railway-kamu>/login` → halaman login harus tampil
   penuh dengan CSS. **Kalau tampil tanpa CSS, berarti aset frontend tidak
   terbawa** — buka `public/build/manifest.json` langsung di browser; kalau 404,
   `npm run build` tidak jalan di dalam image.
3. Buka `https://<domain-railway-kamu>/pasien` tanpa login → harus
   **mengarah (redirect) ke `/login`**, bukan 500.
4. Login dengan `perawat` / `perawat123` → harus masuk ke daftar pasien.
   Kalau masuk tapi **halaman putih kosong**, buka Developer Tools → Console,
   biasanya ada error Vue; periksa langkah 2.
5. Buka `/encounters/enc-159853-icu-20260906/observasi` → harus muncul
   flowsheet observasi beserta skor EWS. Ini menguji Inertia + aset + DB
   sekaligus.

---

## 7. Menjalankan Migrasi

> **PENTING — baca dulu.** Migrasi **tidak** berjalan otomatis pada
> konfigurasi bawaan (`RUN_MIGRATIONS=false`). Ini disengaja: kamu punya
> kesempatan membaca output migrasi sebelum skema berubah, dan tidak ada
> migrasi salah yang diam-diam dieksekusi pada deploy berikutnya.

### 7.1 Cara yang disarankan — manual dari komputer lokal

```bash
# pastikan sudah terhubung & service sudah benar
railway service

# Lihat apa yang akan dijalankan (aman, hanya membaca)
railway run php artisan migrate:status

# Jalankan
railway run php artisan migrate --force
```

Contoh keluaran sehat:

```
INFO  Running migrations.

  2026_09_30_000001_add_clinical_fields_to_users_table ... 12ms DONE
  2026_09_30_000002_create_patients_table ................. 8ms DONE
  ...
  2026_09_30_000014_create_abg_results_table .............. 15ms DONE

INFO  No migration operations to run.
```

> `railway run` mengirim perintah ke container yang sedang berjalan. Pastikan
> **service** yang dipilih adalah service aplikasi, bukan service PostgreSQL.

### 7.2 Cara alternatif — otomatis saat container start

Set `RUN_MIGRATIONS=true` di Railway Variables. `docker/entrypoint.sh` lalu
menjalankannya setiap kali container start, setelah menunggu PostgreSQL siap.

**Kapan cara ini tepat:**
- Kamu tidak bisa menjalankan `railway run` (mis. tidak punya akses CLI).
- Tim kamu tidak punya mekanisme deploy manual sama sekali.

**Kapan cara ini JANGAN dipakai:**
- Ada migrasi yang belum backwards compatible dengan code versi lama.
- Ada beberapa developer yang bisa memicu deploy bersamaan.

Yang perlu kamu sadari: dengan `RUN_MIGRATIONS=true`, **migrasi yang salah akan
berjalan otomatis pada deploy berikutnya.** Untuk database yang berisi data
klinis, manual hampir selalu lebih aman.

### 7.3 Kalau migrasi timeout

Migrasi besar pada koneksi Railway yang lambat bisa melewati batas waktu.
Mitigasi:

- Jalankan migrasi dengan `--force` secara manual, satu service saja.
- Jangan menaruh `DB::statement()` yang berat (mis. `CREATE INDEX` pada tabel
  besar tanpa `CONCURRENTLY`) di dalam migrasi.
- Naikkan timeout Railway di **Settings** → **Deploy** → **Deploy Timeout**.

---

## 8. Seeding Data Demo

### 8.1 Kapan harus dan kapan tidak

| Situasi | Seeding? |
|---|---|
| Showcase/demo untuk atasan atau dosen | **Ya**, sekali. |
| Acceptance test (UAT) | **Ya**, sekali, lalu reset. |
| Produksi dengan data nyata | **TIDAK PERNAH.** |
| Produksi yang masih kosong dan mau diisi dummy | **Tidak.** Buat user secara manual. |

Alasannya: data demo berisi pasien fiktif dengan identitas yang tidak nyata.
Mencampkannya dengan data klinis nyata melanggar etika dan juga aturan
rekam medis, dan tidak ada cara memisahkannya dengan rapi nanti.

### 8.2 Cara menjalankan

```bash
# Cek dulu apa yang akan ditambahkan
railway run php artisan db:seed --list   # nama seeder yang tersedia

# Jalankan
railway run php artisan db:seed --force
```

Seeder di aplikasi ini **idempoten**: semuanya memakai `updateOrCreate` dengan
kunci bisnis (mis. `username` untuk user, `encounter_id` untuk episode), bukan
insert buta. Jadi menjalankannya dua kali tidak menghasilkan baris duplikat.

### 8.3 Isi data demo

| Seeder | Isi |
|---|---|
| `UserSeeder` | 3 akun petugas (lihat README) |
| `PatientEncounterSeeder` | 6 pasien, 6 episode perawatan, ASMED, diagnosis, prosedur, asuhan keperawatan, CPPT |
| `ObservationSeeder` | 60 observasi EWS lengkap dengan koreksi pemberian obat, jawaban bundle, dan perangkat invasif |
| `AbgResultSeeder` | 15 hasil analisa gas darah |
| `SupportResultSeeder` | 118 hasil laboratorium, darah, mikroba, radiologi |

Seeder juga menjalankan pemeriksaan integritas data di akhir dan **gagal dengan
pesan jelas** kalau ada nilai yang tidak valid, atau kalau `ews_total` tidak
sama dengan jumlah skor per parameternya.

### 8.4 Menghapus semua data dan mulai ulang

```bash
# ⚠ HINGGAKAN: menghapus SEMUA tabel, termasuk users
railway run php artisan migrate:fresh --force

# Seed ulang
railway run php artisan db:seed --force

# Buat ulang APP_KEY -> JANGAN, ini akan logout semua user
```

> `migrate:fresh` menghapus tabel `users` juga, termasuk akun kamu. Setelah itu
> kamu harus membuat user admin secara manual.

---

## 9. Domain Kustom dan HTTPS

### 9.1 Menambahkan domain

1. Railway > service aplikasi > **Settings** → **Networking** → **Custom Domain**.
2. Tempel domain kamu, mis. `simrs-ews.rsprotinsulu.go.id`.
3. Railway memberi nilai `CNAME` / `TARGET` untuk ditambahkan di DNS provider.
4. Tunggu sampai status berubah menjadi **Active**. biasanya butuh beberapa
   menit sampai propagasi DNS selesai. Railway sudah memberikan sertifikat
   Let's Encrypt otomatis.

### 9.2 TLS: siapa yang mengakhiri koneksi?

Ini poin yang sering disalahpahami, jadi perpetrator di sini secara eksplisit.

> **Dengan `Dockerfile` di belakang proxy Railway, KAMU TIDAK.perlu — dan
> sebaiknya TIDAK melakukan — terminasi TLS sendiri.**

Caranya begini:

```
Browser  ──HTTPS──▶  Railway edge (proxy)  ──HTTP──▶  Container kamu
        (sertifikat Let's Encrypt milik Railway)   (port 8080, tanpa TLS)
```

Yang terjadi:

1. Browser membuka koneksi ke `https://domain-kamu`.
2. **Proxy Railway**/model yang memegang sertifikat dan mengakhiri koneksi TLS.
3. Proxy lalu meneruskan request ke container kamu lewat **HTTP biasa** di
   jaringan internal.

**Akibatnya:**

- Certificate yang burnt-burn di dalam container**tidak diperlukan** dan juga
  tidak akan dipakai. Meletakkannya di sana hanya menambah ukuran image dan
  complicate renewal.
- `nginx` di dalam image Anda **tidak dikonfigurasi** dengan `listen 443 ssl`
  sama sekali, dan memang tidak boleh. `docker/nginx.conf` dengan sengaja hanya
  `listen 8080` tanpa TLS.
- Request yang sampai ke container sudah HTTP biasa, tapi header
  `X-Forwarded-Proto: https` dan `X-Forwarded-For` tetap ada. Inilah alasan
  `docker/nginx.conf` mengatur `real_ip_header` dan `SESSION_SECURE_COOKIE=true`
  tetap benar: cookie tetap ditandai `Secure` Though koneksi ke backend tidak
  terenkripsi.

### 9.3 Kalau suatu saat kamu harus coveted sendiri

Misalnya kamu memakai layanan di luar proxy Railway. **Jangan pakai `Dockerfile`
sekarang.** Jalur ini membutuhkan file tambahan yang tidak ada di repo ini
(sertifikat + konfigurasi TLS nginx), dan `Dockerfile` sekarang tidak tahu
tentangnya. Railway juga menyediakan opsi "Proxy" vs "Transparent Proxy";
pilih yang sesuai dengan kebutuhanmu dan baca dokumentasi Railway terbaru.

---

## 10. Mengatur Ulang dan Bypass

### 10.1 Membatalkan deploy yang gagal

Klik deploy gagal → **Cancel** atau **Rollback** ke deploy sebelumnya yang
berhasil. Rollback hanya mengubah kode; **tidak** membatalkan migrasi yang sudah
berjalan. Karena itu, jalankan migrasi **setelah** deploy sukses.

### 10.2 Mengganti password database

1. Railway > service **PostgreSQL** → **Variables** → ganti `POSTGRES_PASSWORD`.
2. Save. Plugin akan memperbarui variabel di service aplikasi.
3. **Restart** service aplikasi supaya koneksi baru dipakai.

> Mengganti `POSTGRES_PASSWORD` **tidak** mengubah password user yang sudah
> ada di database. Itu hanya variabel untuk koneksi baru. Data lama tidak hilang.

### 10.3 Deploy dengan cepat tanpa database

`railway up` mengirim seluruh direktori lokal. Kalau ada file besar yang tidak
perlu (mis. `node_modules`, `public/build`), build tetap bisa gagal. Pastikan
`.dockerignore` sudah benar — file itu ada di repo ini.

### 10.4 Melihat log container yang sudah mati

Log container sebelumnya masih bisa dibaca di
**Deployments** → deploy tersebut → **Deploy Logs**, walau container sudah mati.
Log **tidak** hilang saat container di-restart, selama tidak ada *redeploy*.

---

## 11. Tabel Troubleshooting

| Gejala | Penyebab | Solusi |
|---|---|---|
| **HTTP 500 di semua halaman, log tidak ada** | `APP_KEY` kosong | `php artisan key:generate --show` → tempel di Railway Variables. Lihat [5.4](#54-cara-membuat-app_key). |
| **`could not find driver`** saat boot | `pdo_pgsql` tidak terpasang di image | Pastikan build memakai `Dockerfile` (bukan Nixpacks). Cek Build Log harus memuat `build gates passed`. Kalau tidak, image-nya salah. |
| **`APP_KEY not set`** | `APP_KEY` tidak ada atau kosong | Sama seperti baris pertama. Pastikan ada tanda `base64:` dan tidak ada spasi tambahan. |
| **419 Token Mismatch terus-menerus** | `APP_URL` tidak cocok dengan domain yang diakses, **atau** cookie `Secure` terkirim ke HTTP, **atau** `SESSION_DOMAIN` diisi domain lain | 1) Set `APP_URL` persis sama dengan address bar (tanpa slash di akhir). 2) Set `SESSION_SECURE_COOKIE=true`. 3) **Kosongkan** `SESSION_DOMAIN`. 4) Hapus cookie situs di browser, lalu login ulang. |
| **`permission denied` pada `storage/`** | `storage/` atau `bootstrap/cache/` tidak writable oleh user non-root | Di Dockerfile, pastikan ada `chown -R www-data:www-data storage bootstrap/cache` di stage runtime. Di log deploy, cari baris `non-root: storage/ + bootstrap/cache/ sudah writable`. Kalau tidak muncul, entrypoint berhenti lebih dulu. |
| **Migrasi timeout** | Migrasi terlalu berat atau timeout Railway habis | Jalankan manual `railway run php artisan migrate --force`. Naikkan **Deploy Timeout** di Settings. Hindari `CREATE INDEX` berat tanpa `CONCURRENTLY`. |
| **`Route [xxx] could not be found`** setelah menambah rute | Rute tidak ikut terbaca. Penyebab: `route:cache` masih menyimpan snapshot lama, **atau** file `routes/pages.*.php` belum ikut ter-*commit*. | Deploy ulang (image baru akan membangun ulang route cache). Kalau tetap gagal, cek apakah file-nya sudah ter-*push* ke GitHub — `git status`. |
| **`Vite manifest not found at public/build/manifest.json`** | Aset frontend tidak terbawa ke image | 1) Pastikan `public/build` **tidak** masuk `.dockerignore` secara salah (justru harus di-*exclude*, karena dibangun di dalam image). 2) Cek Build Log: adakah `vite build`? 3) Buka `https://domain-kamu/build/manifest.json` di browser — harusnya JSON, bukan 404. |
| **Session tidak bertahan (logout terus)** | `SESSION_DRIVER=file` (file hilang tiap container restart) **atau** `APP_KEY` berubah **atau** `SESSION_SECURE_COOKIE` salah | Set `SESSION_DRIVER=database`. Pastikan `APP_KEY` stabil. Set `SESSION_SECURE_COOKIE=true` untuk HTTPS. |
| **Host/URL tidak cocok (`TrustProxies`)**, atau halaman terbuka di URL Railway yang acak-acakan | `APP_URL` salah, atau `TrustProxies` tidak dikonfigurasi | Set `APP_URL` ke domain kustom kamu. Railway sudah mengirim `X-Forwarded-*`; `docker/nginx.conf` sudah memakai `real_ip_header X-Forwarded-For`. Kalau kamu memakai `php artisan serve` (jalur Nixpacks), mekanisme ini tidak ada. |
| **Halaman tanpa CSS** | Aset `/build/*.css` 404 | Sama dengan baris "Vite manifest not found". |
| **Deploy sukses, tapi domain 404** | Domain belum aktif | Railway > Settings > Networking. Lihat status **Active**. |
| **Quota RAM habis (`OOMKilled`)** | Instance terlalu kecil untuk `pm.max_children=10` | Turunkan instance, atau turunkan `pm.max_children` di `docker/php-fpm.d/zz-app.conf`. |
| **Build gagal di `npm ci`** | `package-lock.json` tidak sinkron | `npm install`, lalu `git add package-lock.json && git commit`. |
| **Build gagal di `composer install`** | `composer.lock` tidak sinkron | `composer update`, lalu `git add composer.lock && git commit`. |

---

## 12. Checklist

### Saat pertama kali setup

- [ ] Project dibuat, GitHub terhubung, `Root Directory` benar
- [ ] Plugin **PostgreSQL** ditambahkan, status **Active**
- [ ] `DATABASE_URL` terlihat di Variables service aplikasi
- [ ] 12 variable wajib terisi (§5.1)
- [ ] `APP_KEY` dibuat dengan `php artisan key:generate --show`
- [ ] `APP_DEBUG=false` diverifikasi **nilainya**, bukan cuma namanya
- [ ] `railway run php artisan migrate --force` sukses
- [ ] `railway run php artisan db:seed --force` (khusus demo)
- [ ] `/up` → 200
- [ ] `/login` → tampil **dengan CSS**
- [ ] `/pasien` → redirect ke `/login` kalau belum login
- [ ] Login `perawat` / `perawat123` → daftar pasien tampil
- [ ] `/encounters/enc-159853-icu-20260906/observasi` → flowsheet tampil

### Setiap kali deploy

- [ ] `git status` bersih untuk file yang di-track
- [ ] Tidak ada `.env` atau `.env.production` yang ikut ter-*commit*
- [ ] Migrasi baru? Jalankan manual **setelah** deploy sukses
- [ ] `APP_DEBUG` masih `false`
- [ ] Log container dicek: ada `build gates passed` dan `nginx foreground di port 8080`

### Setiap kali mengganti domain

- [ ] `APP_URL` di Railway Variables diperbarui
- [ ] Deploy ulang (perubahan variable saja **tidak** berefek)
- [ ] `SESSION_DOMAIN` tetap **kosong**
- [ ] Cache browser dibersihkan, lalu login ulang
