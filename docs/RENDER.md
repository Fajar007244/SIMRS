# Panduan Deploy ke Render (Laravel 12 + PostgreSQL)

Dokumen ini untuk developer **baru** yang belum pernah deploy aplikasi Laravel
ke Render. Setiap langkah disertai **alasannya**, bukan hanya perintahnya.

> Dokumen pendukung: [DEPLOYMENT.md](DEPLOYMENT.md) (arsitektur, konfigurasi
> lokal, model data), [../README.md](../README.md), dan
> [../.env.production.example](../.env.production.example) (referensi lengkap
> semua environment variable).

---

## Daftar Isi

1. [Peta Mental: Apa yang Terjadi Saat Deploy](#1-peta-mental)
2. [Prasyarat: Git Repository](#2-prasyarat-git-repository)
3. [Membuat Database PostgreSQL](#3-membuat-database-postgresql)
4. [Membuat Web Service (Runtime: Docker)](#4-membuat-web-service-runtime-docker)
5. [Environment Variable](#5-environment-variable)
6. [Cara Cepat: render.yaml (Blueprint)](#6-cara-cepat-renderyaml-blueprint)
7. [Deploy Pertama dan Membaca Log](#7-deploy-pertama-dan-membaca-log)
8. [Migrasi dan Seeding](#8-migrasi-dan-seeding)
9. [Domain Kustom dan HTTPS](#9-domain-kustom-dan-https)
10. [Free Tier: Instance Tidur dan Rekomendasi](#10-free-tier-instance-tidur-dan-rekomendasi)
11. [Tabel Troubleshooting](#11-tabel-troubleshooting)

---

## 1. Peta Mental

Sebelum menyentuh dashboard, pahami dulu apa yang terjadi di belakang layar.

```
  GitHub (kode)  --push-->  Render Build  ----->  Render Deploy  ----->  URL publik
                                  |                       |
                                  v                       v
                          build image:                container aplikasi:
                          npm ci + npm run build       nginx + php-fpm (www-data)
                          composer install --no-dev
                          + gerbang sanity check
```

Resource yang akan dibuat:

| Resource | Jenis | Nama di `render.yaml` | Untuk apa |
|---|---|---|---|
| `simrs-ews-db` | Render Postgres | `databases[0]` | Menyimpan seluruh data klinis |
| `simrs-ews-app` | Web Service (Docker) | `services[0]` | Menjalankan aplikasi |

Tiga hal penting yang sering tidak dipahami orang:

1. **Build dan deploy itu dua tahap berbeda.**
   *Build* mengubah kode menjadi *image* (seperti file `.iso`). *Deploy* adalah
   saat image itu dijalankan sebagai container. Kalau build gagal, deploy tidak
   pernah terjadi.

2. **Render tidak menjalankan `composer install` atau `npm run build` sendiri.**
   Semua itu terjadi di dalam *build Docker*, karena proyek ini punya
   `Dockerfile` sendiri. Render hanya menjalankan `docker build` lalu
   `docker start`.

3. **Render mengarahkan trafik ke `$PORT` yang diacak sendiri.**
   Setiap kali service dijadwalkan, Render memilih satu port bebas dan
   menyuntikkannya sebagai environment variable `PORT`. Aplikasi kita wajib
   mengikuti: `docker/entrypoint.sh` menuliskan angka itu ke direktif
   `listen` milik nginx sebelum nginx dijalankan. Angka tetap `8080` hanya
   berlaku untuk `docker compose` lokal.

---

## 2. Prasyarat: Git Repository

> **Ini satu-satunya langkah manual.** Semua langkah lain bisa lewat dashboard
> Render, tapi langkah ini tidak bisa.

Folder `backend/` **saat ini belum menjadi repository Git**. Render hanya bisa
menarik kode dari sebuah repository. Jadi sebelum membuka dashboard, jalankan
perintah ini.

> **Penting soal letak repo:** `Dockerfile` dan `render.yaml` berada di dalam
> folder `backend/`, dan Render mencari `render.yaml` di **root** repository.
> Jadi isi repository GitHub harus berisi isi `backend/` di root - bukan
> folder `backend/` di dalam repository yang lebih besar.

### Perintah

```bash
cd backend

git init
git add .
git commit -m "Deploy ke Render: image produksi, konfigurasi Render, dan dokumentasi"
git branch -M main
git remote add origin https://github.com/<user>/<repo>.git
git push -u origin main
```

Penjelasan per perintah:

| Perintah | Kenapa perlu |
|---|---|
| `git init` | Membuat folder `.git/`. Sampai perintah ini dilakukan, `backend/` belum berupa repository. |
| `git add .` | Menandai semua file untuk ikut commit. |
| `git commit -m "..."` | Menyimpan satu snapshot kode. Tanpa commit, repo kosong. |
| `git branch -M main` | Mengganti nama branch default menjadi `main`, karena `render.yaml` memakai `branch: main`. |
| `git remote add origin ...` | Menghubungkan folder ini ke repository GitHub. Ganti `<user>/<repo>` dengan milikmu. |
| `git push -u origin main` | Mengirim commit ke GitHub. Render menarik dari sini. |

### Sebelum push, pastikan tiga hal

`.gitignore` sudah benar, tapi lebih baik dicek sekali:

```bash
# 1. .env TIDAK ikut ter-commit (exit 0 berarti sudah di-ignore)
git check-ignore .env

# 2. .env.example dan .env.production.example SEHARUSNYA ikut ter-commit
git ls-files | findstr ".env"

# 3. public/build TIDAK ikut ter-commit (dibangun di dalam image)
git ls-files | findstr "public/build"
```

Hasil yang diharapkan:

| Perintah | Hasil benar |
|---|---|
| `git check-ignore .env` | mencetak nama aturan dari `.gitignore`, exit code 0 |
| `git ls-files \| findstr ".env"` | hanya `.env.example` dan `.env.production.example` |
| `git ls-files \| findstr "public/build"` | tidak ada output sama sekali |

Kalau `.env` ikut ter-commit, **hapus dari history GitHub** sebelum lanjut.
`APP_KEY` dan password database yang bocor di GitHub harus dianggap sudah
kompromi dan dirotasi.

---

## 3. Membuat Database PostgreSQL

Kalau kamu memakai `render.yaml` (Bagian 6), bagian ini **tidak perlu dilakukan
manual** - Render akan membuat database-nya sendiri. Bagian ini dijelaskan supaya
kamu tahu apa yang terjadi, dan untuk kasus ketika kamu ingin melihat/mengubah
database secara manual.

### Cara manual

1. Buka <https://dashboard.render.com>, lalu **New > Postgres**.
2. **Name**: `simrs-ews-db`
3. **PostgreSQL Version**: `16` (sama dengan yang dipakai di
   `docker-compose.pgsql.yml`). **Tidak bisa diubah setelah dibuat.**
4. **Region**: `Singapore` paling dekat dengan Indonesia; `Oregon` (default)
   juga berfungsi, hanya lebih lambat.
5. **Plan**: `Free` untuk mencoba. Perhatikan catatan gratis di Bagian 10.

Tekan **Create Database**.

### Apa yang disuntikkan Render ke aplikasi

Ketika sebuah database terhubung ke Web Service, Render menyuntikkan
environment variable berikut **otomatis** - tanpa perlu kita mengetiknya:

| Variabel | Isi |
|---|---|
| `DATABASE_URL` | Connection string lengkap, bentuknya `postgresql://USER:PASSWORD@HOST:PORT/NAMA_DATABASE` |
| `PGHOST` | Hostname internal database |
| `PGPORT` | Port PostgreSQL, biasanya `5432` |
| `PGDATABASE` | Nama database |
| `PGUSER` | Nama user database |
| `PGPASSWORD` | **Password database** |

`config/database.php` membaca `DATABASE_URL` lebih dulu dan menguraikannya
otomatis menjadi host/port/database/username/password. Karena itu **jangan
pernah** isi `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, atau
`DB_PASSWORD` di dashboard - nilainya akan diabaikan begitu `DATABASE_URL` ada.

> **Keamanan:** `PGPASSWORD` adalah password database produksi. Jangan
> menyalinnya ke file, ke chat, ke issue, atau ke screenshot. Kalau
> bocor, rotasi password database dari dashboard Render.

### Membatasi akses database

`render.yaml` memakai `ipAllowList: []`, artinya database **hanya bisa diakses
dari jaringan internal Render** - tidak bisa dijangkau dari internet publik.
Web Service dan database-nya berada di workspace yang sama, jadi keduanya tetap
saling terhubung.

Kalau suatu saat perlu menelusuri database dari komputer sendiri (psql atau
Beekeeper Studio), ubah sementara blok `ipAllowList` di `render.yaml`:

```yaml
    ipAllowList:
      - source: 0.0.0.0/0
        description: sementara untuk akses dari luar
```

...lalu **kembalikan lagi ke `ipAllowList: []`** setelah selesai.

Alternatif yang lebih aman: pakai `render psql simrs-ews-db`, yang membuat
sesi psql melalui jaringan internal tanpa mengubah apa pun.

---

## 4. Membuat Web Service (Runtime: Docker)

1. **New > Web Service**, lalu hubungkan repository GitHub yang tadi kamu push.
2. **Runtime: pilih `Docker`.**

   > ### WAJIB: pilih Docker, bukan PHP
   >
   > Render punya runtime native untuk PHP, dan itu **tidak cocok** untuk
   > proyek ini. Alasannya:
   >
   > * Aset frontend (Vue + Vite) harus dikompilasi dengan Node. Runtime native
   >   PHP Render tidak menjalankan `npm run build`; hasilnya
   >   `Vite manifest not found` di setiap halaman.
   > * Aplikasi ini butuh extension PHP `pdo_pgsql`, `mbstring`, `intl`, dan
   >   `zip`. Runtime native Render tidak menyediakan semuanya itu.
   > * Konfigurasi nginx + php-fpm milik proyek ini (`docker/nginx.conf`,
   >   `docker/php-fpm.d/`) sama sekali tidak dipakai pada runtime native.
   >
   > `Dockerfile` di repo ini sudah menangani semua itu dalam 3 tahap. Kalau ada
   > extension yang hilang, gerbang sanity check di stage 3 menghentikan build
   > dengan pesan jelas, jauh sebelum image dipakai untuk deploy.

3. **Docker Context**: biarkan kosong (root repository).
   **Dockerfile Path**: `./Dockerfile`.
4. **Health Check Path**: `/up`. Path ini terdaftar di `bootstrap/app.php`,
   tidak butuh login, dan tidak menyentuh database.
5. **Instance Count**: `1` (lihat alasannya di `render.yaml`).
6. Jangan buat Environment Variables dulu - bagian itu dijelaskan di Bagian 5.

Tekan **Create Web Service**. Render langsung memulai build pertama.

---

## 5. Environment Variable

Semua variable yang dibaca aplikasi ada di
[../.env.production.example](../.env.production.example). Tabel di bawah
menjawab satu pertanyaan saja: **kalau ini tidak diisi, apa yang rusak?**

### Yang WAJIB diisi manual

| Nama | Contoh | Wajib? | Kalau tidak diisi / salah |
|---|---|---|---|
| `APP_KEY` | `base64:AbCdEf...==` | **YA** | Setiap request 500 dengan `MissingAppKeyException`. `docker/entrypoint.sh` mendeteksinya lebih awal dan menghentikan container dengan pesan jelas. |
| `APP_URL` | `https://simrs-ews-app.onrender.com` | **YA** | Link setelah login melompat ke `localhost`, atau muncul **419 Token Mismatch** karena validasi Origin/Referer CSRF gagal. |
| `DATABASE_URL` | disuntikkan Render | **YA** (otomatis) | Semua query gagal: `SQLSTATE[08006] could not translate host name`. |

**Cara membuat `APP_KEY`** - jalankan di komputer sendiri:

```bash
# Windows dengan XAMPP, jalankan ini dulu di terminal baru:
# $env:PATH = "C:\xampp\php;$env:PATH"

php artisan key:generate --show
```

Outputnya seperti `base64:AbCdEfGh...==`. **Salin seluruh baris itu** (termasuk
awalan `base64:`) ke variable `APP_KEY`.

> **Jangan pakai `generateValue: true` untuk `APP_KEY`.** Fitur itu membuat
> Render menghasilkan "base64-encoded 256-bit value": 44 karakter **tanpa**
> awalan `base64:`. Laravel membutuhkannya tepat **32 byte** setelah awalan itu
> dilepas (`config/app.php` memakai `AES-256-CBC`, dan `Encrypter::supported()`
> menolak key yang panjangnya bukan 32). Hasilnya
> `RuntimeException: Unsupported cipher or incorrect key length` pada setiap
> request. Tetap isi manual lewat `php artisan key:generate --show`.

### Yang disuntikkan platform (JANGAN diisi)

| Nama | Asal | Kalau diisi manual |
|---|---|---|
| `PORT` | Render, angka acak | Mengarahkan trafik ke port yang salah; aplikasi 502 total. |
| `DATABASE_URL` | Render, dari database | Mengganti kredensial asli dengan yang salah; koneksi gagal. |
| `PGHOST` `PGPORT` `PGDATABASE` `PGUSER` `PGPASSWORD` | Render | Sama seperti di atas. |

Di dashboard Render, ketiganya tampil sebagai **read-only**. Itu normal, bukan
error.

### Sisanya (biarkan sesuai `render.yaml`)

| Nama | Nilai di `render.yaml` | Wajib? | Kalau salah |
|---|---|---|---|
| `APP_NAME` | `SIMRS RSP Rotinsulu` | tidak | Judul halaman dan nama cookie berubah |
| `APP_ENV` | `production` | **YA** | `local`/`staging` mengubah perilaku penanganan error dan bisa membocorkan detail |
| `APP_DEBUG` | `false` | **YA** | `true` menampilkan stack trace lengkap beserta potongan database ke siapa pun |
| `APP_LOCALE` | `id` | tidak | Tanggal/angka tampil dalam format Inggris |
| `APP_FALLBACK_LOCALE` | `id` | tidak | Pesan validasi jatuh ke bahasa Inggris |
| `APP_FAKER_LOCALE` | `en_US` | tidak | **Jangan diubah ke `id_ID`** - paket Faker tidak punya locale itu dan `db:seed` akan gagal |
| `DB_CONNECTION` | `pgsql` | **YA** | Default `config/database.php` adalah `sqlite`; di dalam image tidak ada file SQLite yang bisa ditulis |
| `SESSION_DRIVER` | `database` | **YA** | `file` membuat session hilang tiap container di-restart: semua user logout |
| `SESSION_LIFETIME` | `120` | tidak | Sesi terlalu singkat atau terlalu panjang |
| `SESSION_SAME_SITE` | `lax` | tidak | `strict` memaksa user login dua kali; `none` mengizinkan CSRF lintas situs |
| `SESSION_HTTP_ONLY` | `true` | tidak | `false` membiarkan JavaScript membaca cookie session, jadi mitigasi XRF hilang |
| `SESSION_PATH` | `/` | tidak | Kalau bukan `/`, cookie tidak terkirim ke halaman aplikasi dan login selalu gagal tanpa pesan jelas |
| `SESSION_EXPIRE_ON_CLOSE` | `false` | **YA** untuk klinik | `true` membuat session ikut hilang begitu tab ditutup, padahal petugas sering membiarkan tab terbuka antar shift |
| `SESSION_SECURE_COOKIE` | `true` | **YA** | `false` mengirim cookie lewat HTTP sehingga bisa dicegat |
| `CACHE_STORE` | `database` | tidak | `file` bisa membuat tampilan tidak sinkron antar container |
| `QUEUE_CONNECTION` | `sync` | tidak | Tidak ada job di aplikasi ini; `database` tanpa worker akan menumpuk |
| `BROADCAST_CONNECTION` | `log` | tidak | Membuka koneksi websocket yang tidak perlu |
| `FILESYSTEM_DISK` | `local` | tidak | `s3` tanpa kredensial AWS akan gagal saat menyimpan berkas |
| `LOG_CHANNEL` | `stderr` | **YA** | `single` menulis ke `storage/logs/laravel.log` **di dalam container**; begitu restart, log hilang. Gejalanya "error 500 tapi log kosong" |
| `LOG_LEVEL` | `info` | tidak | `debug` membanjiri kuota log; `error` membuat masalah lambat terdeteksi |
| `BCRYPT_ROUNDS` | `12` | tidak | Di bawah 10 tidak aman; di atas 14 login jadi sangat lambat |
| `CACHE_CONFIG` | `true` | tidak | `false` membuat setiap request membaca ulang semua file config |
| `CACHE_ROUTES` | `true` | tidak | Route dibaca ulang tiap request |
| `CACHE_VIEWS` | `true` | tidak | Blade dikompilasi tiap request |
| `DB_WAIT_SECONDS` | `60` | tidak | Kurang dari 60 = gagal cepat saat PostgreSQL lambat; tidak ada batas = menggantung |
| `DB_WAIT_INTERVAL` | `2` | tidak | Jeda antar percobaan koneksi |
| `RUN_MIGRATIONS` | `true` | tidak | `false` = schema harus dibuat manual (lihat Bagian 8) |
| `SEED_DEMO_DATA` | `false` | **YA** | `true` di produksi berisiko menduplikasi baris klinis (lihat Bagian 8) |

> **Setelah mengubah environment variable, Render otomatis memicu deploy
> ulang.** Jadi tidak perlu menekan tombol deploy lagi. Tapi karena
> `config:cache` membekukan nilai `env()` saat container start, nilai baru
> baru benar-benar berlaku setelah container yang baru start.

---

## 6. Cara Cepat: `render.yaml` (Blueprint)

Semua langkah Bagian 3 sampai Bagian 5 bisa dilewati. File
[`render.yaml`](../render.yaml) di root repository sudah mendeklarasikan
database dan web service sekaligus, lengkap dengan environment variable-nya.

### Cara memakainya

1. Pastikan repository sudah di-push (Bagian 2).
2. Buka <https://dashboard.render.com>
3. **New > Blueprint**
4. Pilih repository yang tadi kamu push. Render membaca `render.yaml` dari
   root repository itu.
5. Render menampilkan ringkasan resource yang akan dibuat. Tekan **Apply**.
6. Karena `APP_KEY` dan `APP_URL` ditandai `sync: false`, Render membuka
   **form isian** untuk keduanya. Isi di situ:
   * `APP_KEY` = hasil `php artisan key:generate --show`
   * `APP_URL` = `https://simrs-ews-app.onrender.com`
     (atau URL punyamu, **tanpa** garis miring di akhir)
7. Build pertama berjalan otomatis.

### Apa yang dilakukan `render.yaml`

| Isi file | Efeknya di dashboard |
|---|---|
| `databases[0]` | Membuat PostgreSQL `simrs-ews-db`, plan `free`, versi 16 |
| `services[0].runtime: docker` | Web Service dari `./Dockerfile` |
| `services[0].healthCheckPath: /up` | Probe `/up` tiap container start |
| `services[0].numInstances: 1` | Satu instance, dengan alasan di komentar file |
| `DATABASE_URL` + `fromDatabase` | Render menyuntikkan connection string database ke service |
| `envVars` dengan `value:` | Nilai tetap, selalu sinkron dengan file |
| `APP_KEY`, `APP_URL` dengan `sync: false` | Render meminta isian manual, tidak menimpa nilai lama |
| `ipAllowList: []` | Database hanya bisa diakses dari jaringan internal Render |

### Kalau Blueprint gagal

Render menampilkan pesan validasi yang sangat spesifik. Dua hal yang paling
sering:

* **`branch` could not be found** - `render.yaml` memakai `branch: main`, jadi
  pastikan branch itu benar-benar ada (`git branch -M main` di Bagian 2).
* **`name` already in use** - dipakai nama resource yang sama. Ganti `name`
  di `render.yaml`, atau hapus resource lama dari dashboard.

### Memvalidasi tanpa dashboard

Kalau punya [Render CLI](https://render.com/docs/cli):

```bash
render login
render blueprints validate render.yaml
```

Perintah ini mengecek sintaks YAML, skema, nama plan/region yang valid, dan
konflik dengan resource yang sudah ada.

### Blueprint vs manual

| | `render.yaml` | Dashboard manual |
|---|---|---|
| Cocok untuk | Tim lebih dari satu orang, atau mau dokumentasi ikut ter-version | Sekali deploy, lalu disetel manual |
| Risiko | Sync berikutnya bisa menimpa perubahan manual di dashboard | Konfigurasi tidak tercatat di Git |
| Migrasi/`APP_URL` | Otomatis ikut ter-commit | Diatur manual di dashboard |

Untuk proyek internal rumah sakit, **`render.yaml` lebih aman**: semua
environment variable tercatat di Git dan bisa diaudit, dan tidak ada
perbedaan antara "yang tertulis" dengan "yang benar-benar jalan".

---

## 7. Deploy Pertama dan Membaca Log

Buka service di dashboard, lalu tab **Logs**.

### Dua jenis log yang berbeda

| Jenis | Tombol | Isi |
|---|---|---|
| **Build log** | `Build` di halaman deploy | `docker build`: `npm ci`, `npm run build`, `composer install`, gerbang sanity check |
| **Deploy log** | `Deploy` di halaman deploy | Output *container* yang sedang berjalan: entrypoint, migrasi, dan log aplikasi |

Kalau deploy gagal, selalu cek **build log** lebih dulu: build yang gagal tidak
pernah menghasilkan container.

### Build log yang sehat

Tanda stage yang penting, berurutan:

```
[stage 1/3] npm ci
[stage 1/3] npm run build
vite v7.x building for production...
[stage 1/3] build gates: public/build/manifest.json OK
[stage 2/3] composer install --no-dev --no-scripts
[stage 3/3] build gates passed
```

Baris `build gates passed` di stage 3 adalah penanda image benar-benar siap.
Dockerfile punya beberapa gerbang yang `exit 1` kalau ada extension PHP yang
hilang, `manifest.json` yang tidak ada, `vendor/autoload.php` yang tidak ada,
atau `storage/` yang tidak writable. Kalau baris itu muncul, image-nya benar.

### Deploy log yang sehat

Semua baris ini berasal dari `docker/entrypoint.sh`:

```
[entrypoint] nginx akan listen di port 10000 (dari /etc/nginx/nginx.conf, disalin ke /tmp/nginx-runtime.conf)
[entrypoint] non-root: storage/ + bootstrap/cache/ sudah writable, chown dilewati
[entrypoint] php artisan config:cache
[entrypoint] php artisan route:cache
[entrypoint] route cache OK
[entrypoint] php artisan view:cache
[entrypoint] PostgreSQL siap pada percobaan ke-1
[entrypoint] menjalankan: php artisan migrate --force
[entrypoint] migrate SELESAI
[entrypoint] menjalankan php-fpm (background)
[entrypoint] php-fpm siap di 127.0.0.1:9000
[entrypoint] nginx foreground di port 10000, container siap
```

Dua baris yang paling penting:

| Baris | Artinya |
|---|---|
| `nginx akan listen di port <angka>` | nginx sudah diarahkan ke port yang benar. **Angka ini harus sama dengan `PORT` yang disuntikkan Render.** Kalau tertulis `8080` di Render, ada yang salah - lihat Bagian 11. |
| `container siap` | Entry point selesai dan nginx sudah jalan di foreground. Dari titik ini, service sudah bisa menerima trafik. |

Kalau `RUN_MIGRATIONS=false`, dua baris `migrate` tidak muncul. Itu normal.

### Memantau log dari terminal

```bash
render logs --resources <SERVICE_ID> --tail
```

Dari dashboard, ID service ada di URL halaman service
(`dashboard.render.com/web/srv-xxxx`).

---

## 8. Migrasi dan Seeding

### 8.1 Migrasi otomatis

`render.yaml` menyetel `RUN_MIGRATIONS=true`. Setiap kali container start,
`docker/entrypoint.sh` melakukan ini (dalam urutan yang disengaja):

1. Validasi dan rewrite port nginx.
2. Pastikan `storage/` dan `bootstrap/cache/` writable.
3. Tunggu PostgreSQL siap (maks `DB_WAIT_SECONDS` = 60 detik).
4. Ambil lock `mkdir /tmp/.laravel-migrate.lock` supaya dua proses tidak
   menjalankan migrasi bersamaan di dalam satu container.
5. `php artisan migrate --force`.

> **Kapan berhenti memakainya `RUN_MIGRATIONS=true`?** Setelah skema database
> sudah stabil. Lalu turunkan ke `false` di dashboard (atau di `render.yaml`)
> dan jalankan migrasi manual supaya output-nya bisa kamu baca sebelum data
> berubah.

### 8.2 Menjalankan perintah di dalam container

**Cara 1: shell interaktif.** Butuh service dalam keadaan **running**.

```bash
render login
render ssh simrs-ews-app
```

Kamu akan masuk ke dalam container yang sedang berjalan. Dari sana:

```bash
php artisan migrate --status
php artisan migrate --force
php artisan db:seed --force
php artisan about
```

Kalau `render ssh` menolak karena instance sedang tidur, buka URL service di
browser dulu (itu membangunkannya), baru ulangi perintahnya.

Ada juga `render ssh simrs-ews-app --ephemeral`, yang membuat container
sementara yang terisolasi. HATI-HATI: instance ephemeral memakai database
produksi yang sama, jadi `db:seed` di sana sama berbahaya seperti di produksi.

> **Catatan penting:** Render CLI **tidak punya** perintah `render run`
> (perintah itu milik Railway). Kalau tutorial yang kamu baca memakai
> `render run`, itu salah. Perintah yang benar untuk pekerjaan satu-off adalah
> `render jobs create` di bawah. Ketik `render` tanpa argumen, atau
> `render help <perintah>`, untuk melihat daftar perintah pada versi CLI yang
> kamu punya - CLI Render berkembang cepat.

**Cara 2: one-off job** (tidak perlu shell interaktif, hasilnya ada di log):

```bash
render jobs create simrs-ews-app --start-command "php artisan migrate --force"
```

Lihat hasilnya di dashboard pada tab **Jobs**.

**Cara 3: dashboard.** Buka service > **Shell**, lalu jalankan perintahnya
secara interaktif.

### 8.3 Seeding - BACA DULU

> ### PERINGATAN KERAS: jangan pernah seed database produksi
>
> `php artisan db:seed` membuat **data pasien contoh**: 6 pasien, 6 episode
> perawatan, 60 observasi EWS, 218 catatan pemberian obat, 780 jawaban bundle,
> dan 3 akun demo dengan password yang tertulis di README.
>
> Seeder ini tidak idempoten **sepenuhnya**. Menjalankannya pada database yang
> sudah berisi data pasien nyata berisiko:
>
> * menduplikasi baris observasi dan CPPT,
> * mencampur data fiktif dengan data nyata sehingga tidak bisa dibedakan
>   saat audit,
> * membuat laporan EWS dan bundle HAI menghitung angka yang tidak pernah
>   terjadi di dunia nyata.
>
> **Aturan:**
>
> 1. Seed hanya untuk demo/presentasi, **sekali**, lalu ubah atau hapus
>    database demo-nya.
> 2. `SEED_DEMO_DATA` **harus tetap `false`**. Nilai `true` membuat
>    `db:seed` berjalan di setiap boot - itu bom waktu.
> 3. Sebelum seed, pastikan `DATABASE_URL` yang dipakai benar-benar database
>    demo. Database Render punya hostname internal yang diawali `dpg-`.
> 4. Setelah seed selesai, **jangan pernah** menjalankan `db:seed` lagi di
>    database itu.
> 5. Menghapus akun demo (`perawat`, `bidan`, `dokter`) dan data contoh adalah
>    langkah wajib sebelum aplikasi dipakai sungguhan.

Untuk menyiapkan demo dengan aman: buat database **terpisah** khusus demo, seed
sekali di sana, lalu hapus setelah demo selesai.

---

## 9. Domain Kustom dan HTTPS

### Render sudah mengurus TLS - jangan diurus ulang

Render sudah menyediakan sertifikat HTTPS dan mengakhiri koneksi TLS **di
depan** service kamu. Artinya:

```
Browser  --HTTPS-->  Render (sertifikat, redirect HTTP -> HTTPS)  --HTTP-->  container kamu (nginx, port 10000)
```

Yang **tidak boleh** kamu lakukan:

| Jangan | Kenapa |
|---|---|
| Mount sertifikat sendiri ke `/etc/nginx` | Render sudah mengakhiri TLS; sertifikat dua lapis hanya menambah titik gagal. |
| Menambahkan `ssl_certificate` / `ssl_certificate_key` ke `docker/nginx.conf` | Butuh jalur private key di dalam image - Secrets di image bisa bocor lewat registry. |
| Menonaktifkan redirect HTTP -> HTTPS | Pengguna bisa masuk lewat HTTP, dan cookie-nya jadi bisa dicegat. |

Kalau kamu merasa "saya harus mengurus sertifikat sendiri", itu karena mengganti
Render dengan VPS sendiri. Untuk Render, HTTPS sudah termasuk gratis.

### Menambahkan domain kustom

1. Beli domain (atau subdomain) dari registrar mana pun.
2. Di dashboard Render, buka service > **Settings > Custom Domains** >
   **Add Custom Domain**, lalu masukkan domainnya, mis.
   `simrs.rotinsulu.go.id`.
3. Render memberi tahu DNS yang harus dibuat. Tambahkan CNAME yang diarahkan
   ke `nama-service.onrender.com`.
4. Tunggu sampai statusnya **Verified**. Render otomatis menerbitkan
   sertifikat Let's Encrypt dan memperbaru sertifikatnya sendiri.

### Dua environment variable yang wajib mengikuti domain

Setelah domain kustom aktif, perbarui **keduanya**:

```
APP_URL=https://simrs.rotinsulu.go.id
SESSION_SECURE_COOKIE=true
```

| Variabel | Kenapa |
|---|---|
| `APP_URL` | Menghasilkan link absolut yang benar di dalam halaman, dan ALASAN utama validasi CSRF. Salah set = **419 Token Mismatch** pada setiap POST (termasuk form login). |
| `SESSION_SECURE_COOKIE` | Kalau dibiarkan `false` selama masih mencoba lewat `onrender.com`, begitu pindah ke domain kustom cookie bisa terkirim lewat HTTP. Tetap biarkan `true`. |

Setelah mengubah `APP_URL`, Render otomatis deploy ulang. Sesi yang sedang
berjalan akan terputus karena cookie lama di-sign dengan domain berbeda - itu
normal, pengguna cukup login ulang.

---

## 10. Free Tier: Instance Tidur dan Rekomendasi

### Apa yang terjadi di paket gratis

Instance web service paket `Free` **tidur (spin down) setelah sekitar 15 menit
tanpa request**. Setelah tidur, instance dihentikan dan biaya komputasi
berhenti.

Konsekuensinya:

* **Request pertama setelah tidur tidak langsung dijawab.** Render harus
  membangunkan instance lebih dulu: start container, menjalankan
  `docker/entrypoint.sh` (yang berarti `config:cache`, `route:cache`,
  `view:cache`, dan satu pengecekan koneksi ke PostgreSQL), lalu menyalakan
  nginx dan php-fpm. Semua itu butuh **30 sampai 60 detik**.
* 30 sampai 60 detik itu **terlalu lama** untuk konteks aplikasi ini. Perawat
  menekan tombol Simpan pada formulir observasi EWS, lalu menunggu spinner
  selama hampir satu menit.
* Setelah bangun, instance akan tidur lagi 15 menit kemudian.
* Database Postgres paket `Free` hanya punya kuota 1 GB **dan kedaluwarsa
  setelah 30 hari**. Setelah tanggal itu, instance dihapus permanen beserta
  seluruh isinya.

### Rekomendasi untuk sistem ini

> **Pakai instance berbayar yang selalu hidup untuk sistem klinis.**
>
> Bukan karena aplikasinya berat, tapi karena **tundaannya tidak bisa
> diterima**.

Alasannya:

1. **EWS adalah sistem peringatan dini.** Kegunaan aplikasi ini adalah memberi
   tahu perawat bahwa seorang pasien sedang memburuk. Kalau satu tombol terasa
   lambat 45 detik karena container sedang bangun, perawat akan belajar
   mengabaikan aplikasinya - dan seluruh tujuan sistem ini jadi sia-sia.

2. **Data pasien tidak bisa menunggu.** Kalau formulir observasi gagal terkirim
   karena timeout, perawat mungkin akan mencatat ulang dari ingatan. Ingatan
   perawat pada jam tiga pagi adalah sumber data yang paling tidak bisa
   diandalkan.

3. **Kuota build gratis terbatas.** Build Docker 3 tahap (Node lalu Composer)
   memakan sebagian besar kuota build bulanan. Setelah kuota habis, Render
   berhenti build otomatis.

4. **Database gratis hangus di hari ke-30.** Untuk data pasien, kehilangan
   database tanpa peringatan adalah hal yang tidak bisa diterima.

5. **Shell untuk `render ssh` sering tidak tersedia** pada instance gratis
   yang sedang tidur, sehingga menjalankan migrasi manual jadi merepotkan.

### Rekomendasi konkret

| Resource | Plan minimal yang disarankan | Alasan |
|---|---|---|
| Web Service | `starter` (0.5 CPU / 512 MB) | Tidak pernah tidur, selalu merespons |
| Postgres | `basic-256mb` (0.5 CPU / 1 GB) atau lebih besar | Tidak hangus di hari ke-30 |
| Region | `singapore` | Latensi terendah dari Indonesia |

Naikkan plan di dashboard: service > **Settings > Plan**. Tidak perlu rebuild
image, dan tidak ada downtime selain restart singkat.

Kalau biaya benar-benar tidak tersedia sekarang:

1. Paket **free** tetap berguna untuk mencoba seluruh alur deploy-nya.
2. **Jangan** memasukkan data pasien nyata ke instance gratis.
3. Ingatkan seluruh tim: begitu 15 menit tidak ada orang yang membuka aplikasi,
   halaman berikutnya akan terasa lambat.
4. Rencanakan naik ke plan berbayar sebelum aplikasi dipakai sungguhan.

### Yang perlu dicek dari dashboard

Setelah deploy pertama, buka service dan periksa:

| Yang dicek | Letak di dashboard |
|---|---|
| Plan | service > Settings > Plan |
| Health Check Path | service > Settings > Health Check Path = `/up` |
| Environment variable | service > Environment > Environment Variables |
| Database | database > Overview |
| Masa berlaku database | database > Overview (plan gratis ada tanggal kedaluwarsa) |

---

## 11. Tabel Troubleshooting

| Gejala | Penyebab | Solusi |
|---|---|---|
| **502 / "service did not respond"** | Tidak ada yang listen di port yang Render tunggu, atau nginx gagal start | 1. Buka deploy log, cari baris `nginx akan listen di port <angka>` dan cocokkan angkanya dengan nilai `PORT`.<br>2. Kalau baris itu tidak ada, cari pesan `FATAL` dari entrypoint.<br>3. Buka tab **Events**: kalau ada restart berulang, container-nya crash loop. |
| **Container terus `unhealthy`, lalu Render me-restart** | `HEALTHCHECK` mengetuk port yang salah | `Dockerfile` memakai `"http://127.0.0.1:${PORT:-8080}/up"`, jadi angkanya ikut `PORT`. Kalau angka pada baris `listen` di deploy log tidak sama dengan `PORT`, image yang jalan versi lama: **Manual Deploy > Clear Build Cache**. Kalau angkanya sudah sama tetapi tetap unhealthy, `curl` di dalam container tidak bisa menjangkau `127.0.0.1`; cek apakah ada proxy atau firewall internal workspace. |
| **`could not find driver`** | Extension `pdo_pgsql` tidak termuat di dalam image | Build seharusnya gagal dengan pesan `FATAL: pdo_pgsql tidak termuat`. Kalau deploy sukses tapi query-nya gagal, image yang jalan bukan hasil build ini. Deploy ulang dengan **Clear Build Cache**. |
| **`MissingAppKeyException`** atau `Unsupported cipher or incorrect key length` | `APP_KEY` kosong, atau panjangnya bukan 32 byte | Isi `APP_KEY` dengan output `php artisan key:generate --show`, termasuk awalan `base64:`. Jangan pakai `generateValue: true`, format Render tidak cocok untuk Laravel. Tunggu deploy ulang selesai. |
| **419 Token Mismatch** | `APP_URL` tidak sama persis dengan URL di address bar | Samakan `APP_URL`, termasuk `https://` dan tanpa garis miring di akhir. Kalau masih 419 setelah itu, hapus cookie situs tersebut lalu login ulang: cookie lama membawa token CSRF dari `APP_URL` yang lama. |
| **`permission denied` pada `storage/` atau `bootstrap/cache/`** | Direktori itu tidak writable oleh user non-root | `Dockerfile` sudah menjalankan `chown -R www-data:www-data` dan `chmod 2775` saat build, plus gerbang `test -w storage` yang menggagalkan build kalau gagal. Kalau muncul saat runtime, kemungkinan ada volume eksternal yang menimpa. Hapus volume itu. |
| **Migrasi timeout / `PostgreSQL tidak siap dalam 60s`** | Database belum siap menerima koneksi, atau `DATABASE_URL` salah | 1. Cek status database di dashboard, mungkin masih provisioning.<br>2. Naikkan `DB_WAIT_SECONDS` ke `120` sementara.<br>3. Pastikan `DATABASE_URL` benar-benar terisi. |
| **Route not found untuk `/pasien` atau `/encounters/{id}/...`** | `route:cache` membekukan daftar route | `docker/entrypoint.sh` menjalankan `config:clear` dan `optimize:clear` lebih dulu, jadi `route:cache` selalu dibangun dari kondisi bersih. Kalau masih route not found, cek `routes/web.php` memang memanggil file route-nya, karena loader memakai `glob()`. |
| **`@vite manifest not found`** | Aset frontend tidak terbangun di dalam image | Build seharusnya gagal dengan pesan `FATAL: public/build/manifest.json tidak dihasilkan oleh vite build`. Kalau tidak, berarti `public/build` dari mesin lokal ikut terbawa dan bentrok dengan hash hasil build image. Pastikan `.dockerignore` masih meng-exclude `public/build` dan `.gitignore` masih meng-exclude `/public/build`, lalu deploy ulang dengan **Clear Build Cache**. |
| **Session tidak bertahan, user logout sendiri** | `SESSION_DRIVER` bukan `database` | Pastikan `SESSION_DRIVER=database` dan tabel `sessions` sudah ada, dibuat oleh migrasi `0001_01_01_000000_create_users_table.php`. Kalau `SESSION_LIFETIME` terlalu kecil, sesinya memang habis; default 120 menit wajar untuk satu shift. |
| **Request pertama setelah lama terasa sangat lambat (30-60 detik)** | Instance gratis sedang bangun dari tidur | Sudah dijelaskan di Bagian 10. Solusi permanen: naikkan ke plan berbayar. |
| **Tidak bisa `render ssh`** | Instance sedang tidur, atau paket tidak menyediakan shell | Buka URL service di browser dulu untuk membangunkannya, lalu ulangi. Alternatifnya `render jobs create`. |
| **`curl: (6) Could not resolve host` di dalam `render ssh`** | Container belum selesai menghubungi database | Coba lagi setelah 10 detik. Kalau tetap gagal, cek `DATABASE_URL` masih terisi. |
| **Render CLI: `unknown command "run"`** | `render run` itu perintah Railway, bukan Render | Pakai `render jobs create <service> --start-command "..."` atau `render ssh <service>`. Lihat Bagian 8.2. |
| **Blueprint gagal: `branch ... could not be found`** | `render.yaml` memakai `branch: main`, tapi repo tidak punya branch itu | Jalankan `git branch -M main` lalu `git push -u origin main`. |
| **Build gagal di `npm ci`** | `package.json` dan `package-lock.json` tidak sinkron | Jalankan `npm install` di lokal, lalu commit ulang `package-lock.json`. |

---

## Appendix: Checklist Sebelum Dipakai Sungguhan

- [ ] Repository sudah di-push, branch `main`
- [ ] `.env` tidak ada di Git (`git check-ignore .env` berhasil)
- [ ] `APP_KEY` diisi dengan output `php artisan key:generate --show`
- [ ] `APP_URL` sama persis dengan URL di address bar
- [ ] `APP_DEBUG=false`
- [ ] `SESSION_DRIVER=database` dan `SESSION_SECURE_COOKIE=true`
- [ ] `LOG_CHANNEL=stderr`
- [ ] `SEED_DEMO_DATA=false`, ditegaskan ulang setelah demo selesai
- [ ] `RUN_MIGRATIONS` disetel sesuai kebutuhan tim
- [ ] Database Postgres tidak punya IP Allow List terbuka ke `0.0.0.0/0`
- [ ] Plan instance bukan `free` kalau aplikasi menyimpan data pasien
- [ ] Akun demo `perawat`, `bidan`, dan `dokter` dihapus
- [ ] `public/build` tidak di-commit, karena dibangun di dalam image
