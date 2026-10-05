# Deploy ke Vercel (Hobby) - Panduan Lengkap

Dokumen ini untuk Mendploy aplikasi **SIMRS RSP Rotinsulu** (Laravel 12 +
Inertia 2 + Vue 3) ke **Vercel** memakai paket **Hobby**.

> Jalur ini adalah **OPSIONAL dan TAMBAHAN**. Jalur Docker / Railway / Render
> tetap utuh dan tidak berubah sama sekali. Kalau Vercel ternyata merepotkan,
> hapus `vercel.json`, `api/`, dan `.vercelignore`, lalu kembali ke
> `render.yaml` atau `Dockerfile`. Tidak ada satu pun file di jalur lama yang
> ikut berubah.

Kalau ini pertama kalinya kamu memakai Vercel, baca dokumen ini dari atas
sampai bawah. Semua langkah sudah urut.

---

## 0. Isi folder yang relevan

| Berkas | Gunanya |
|---|---|
| `vercel.json` | Konfigurasi deploy: build aset frontend, runtime PHP, routing, path tulis ke `/tmp` |
| `api/index.php` | Pintu masuk function. Melayani aset `public/`, lalu meneruskan ke Laravel |
| `.vercelignore` | Daftar berkas yang tidak ikut ter-upload |
| `.env.production.example` bagian 16 | Daftar variabel khusus Vercel |

---

## 1. Prasyarat

### 1.1 Node.js

Vercel butuh Node.js. Pastikan `node -v` dan `npm -v` jalan di terminal.
(Node 18 ke atas sudah cukup; Vercel sendiri memakai Node 22.)

### 1.2 Pasang Vercel CLI

CLI Vercel **tidak** ikut ter-install bersama Node. Pasang sekali saja:

```bash
npm i -g vercel
```

Cek hasilnya:

```bash
vercel --version
```

Kalau muncul nomor versi berarti beres.

### 1.3 Login ke Vercel

Sekali saja per komputer:

```bash
vercel login
```

Perintah ini membuka browser untuk authorizing. **Ini bagian kamu pribadi** -
prompt ini tidak pernah menjalankannya untukmu.

### 1.4 Hubungkan folder ini ke proyek Vercel

```bash
cd /path/ke/backend
vercel link
```

`vercel link` akan menanyakan:

- **Which scope?** pilih akun/organisasimu.
- **Link to existing project?** pilih `Create a new project` (pertama kali),
  atau pilih proyek yang sudah ada kalau kamu deploy ulang.
- **Project name?** bebas, misalnya `simrs-ews-app`.

Selesai akan dibuat folder `.vercel/` berisi `project.json`. **Folder `.vercel/`
ini jangan pernah di-commit** (sudah masuk `.gitignore`).

Cek dulu tanpa deploy apa pun:

```bash
vercel project ls
```

---

## 2. Siapkan database Neon

Vercel tidak menyediakan PostgreSQL di paket Hobby, jadi kita pakai **Neon**
(gratis). Neon memberi PostgreSQL terkelola dengan koneksi dari luar jaringan.

### 2.1 Buat database

1. Buka <https://neon.tech> dan daftar / login.
2. Klik **New Project**.
3. Pilih region yang paling dekat dengan Regionserver Vercel kamu
   (default `iad1` = Washington, D.C.).
4. Beri nama, misalnya `simrs-ews`.
5. Selesai. Neon otomatis membuat branch `main`.

### 2.2 Ambil connection string

1. Di dashboard proyek Neon, klik menu **Connection details** (atau ikon
  .database di sidebar).
2. Pastikan **Type** = `Pooled connection` (host berakhiran `-pooler`).
   Untuk `DATABASE_URL` yang dipakai aplikasi, bentuk **pooled** lebih aman:
   aplikasi membuka koneksi singkat per request dan koneksi langsung bisa
   habis.
3. Salin stringnya. Bentuknya:

```
postgresql://SIMRS:PASSWORD_ANDA@ep-xxxx-pooler.us-east-2.aws.neon.tech/neondb?sslmode=require
```

4. Jangan pernah commit string ini. Ia menjadi environment variable
   `DATABASE_URL` di Vercel (lihat bagian 4).

> Password bisa diubah kapan saja di Neon (**Settings > Reset password**).
> Kalau bocor, reset langsung, lalu update `DATABASE_URL` di Vercel dan
> redeploy.

---

## 3. Aset frontend dibangun sendiri oleh Vercel

**Tidak ada langkah build manual lagi.** Kamu tidak perlu menjalankan
`npm run build` sebelum deploy, dan tidak perlu mengingatinya sama sekali.

`vercel.json` memuat `buildCommand`, dan Vercel menjalankannya di build step
pada setiap deployment:

```json
"buildCommand": "npm ci && npm run build && node -e \"...cek manifest.json...\""
```

Tiga bagiannya:

1. `npm ci` - memasang dependensi dari `package-lock.json`.
2. `npm run build` - menjalankan `vite build`, menghasilkan `public/build/`.
3. Pengecekan terakhir - kalau `public/build/manifest.json` tidak ada setelah
   build, build **digagalkan** dengan pesan yang menyebut `ViteManifestNotFound`.
   Ini membuat kegagalan ketahuan saat build, bukan muncul sebagai halaman
   tanpa CSS setelah deploy.

### 3.1 Kenapa `public/build` tetap tidak di-commit

`public/build` **memang tidak ada di repository** - sudah masuk `.gitignore`,
dan itu tetap benar. Kalau hasil build lokal ikut ter-commit, nama berkasnya
ber-hash bisa tidak cocok dengan source yang sedang di-deploy.

Karena Vercel yang membangunnya, hasilnya **pasti** cocok dengan commit yang
sedang di-deploy. Ini juga membuat deploy lewat GitHub benar: Vercel clone
repository (yang memang tidak berisi `public/build`), lalu `buildCommand`
yang menghasilkannya. Deploy lewat `vercel` CLI dan deploy lewat push ke
GitHub jadi sama-sama benar tanpa langkah tambahan.

> `public/build` **tidak boleh** masuk `.vercelignore`. Kalau ikut ter-ignore,
> Vercel tidak pernah mengirimkannya ke function dan `api/index.php` tidak
> punya aset untuk dilayani. Isi bagian "YANG SENGAJA TIDAK DI-IGNORE" di
> `.vercelignore` sudah menjelaskan hal ini.

> Catatan teknis: runtime PHP `vercel-php` menjalankan `composer install`
> sendiri di build step, tapi **tidak** menjalankan npm. `buildCommand`
> di `vercel.json` adalah yang memasang dan membangun aset frontend, dan itu
> berjalan **sebelum** function `api/index.php` dibundel. Urutannya: Vercel
> mengurutkan builder, dan builder frontend (`@vercel/static-build`) selalu
> lebih dulu daripada runtime komunitas seperti `vercel-php`. Karena itu
> `public/build` sudah ada waktu runtime PHP mem-GLOB isi project untuk
> masuk ke dalam function.

---

## 4. Environment variables di dashboard Vercel

Semua secret hanya hidup di sini. **Jangan** membuat file `.env` di repository
untuk Vercel - `.vercelignore` memang memblokirnya, tapi jangan
dibergantungkan pada itu.

### 4.1 Buka halaman environment variables

1. Buka dashboard Vercel, pilih proyekmu.
2. Tab **Settings** > **Environment Variables**.
3. Tombol **Add Environment Variable** satu per satu.
4. Centang **All Environments** (Production, Preview, Development) supaya
   deploy berikutnya tidak lupa.

### 4.2 Tabel variabel yang wajib diisi

| Nama | Contoh nilai | Wajib? | Kalau tidak diisi / salah |
|---|---|---|---|
| `APP_ENV` | `production` | Ya | `local` memunculkan halaman error detail beserta nilai environment. Risiko kebocoran data. |
| `APP_DEBUG` | `false` | Ya | `true` = setiap exception menampilkan stack trace lengkap, query, dan potongan env. Jangan untuk produksi. |
| `APP_KEY` | `base64:AbCdEf...==` | **Ya, wajib** | Semua request error `Unsupported cipher or incorrect key length`, dan session/cookie tidak bisa dienkripsi. **Jangan pakai generateValue Vercel** - lihat bagian 4.3. |
| `APP_URL` | `https://simrs-ews-app.vercel.app` | Ya | Link setelah login melompat ke `localhost`, dan sering muncul 419 Token Mismatch karena validasi Origin gagal. Harus **persis** sama dengan URL publik, tanpa garis miring di akhir. |
| `APP_NAME` | `SIMRS RSP Rotinsulu` | Tidak | Pakai nilai bawaan Laravel. Boleh diisi agar sama seperti produksi lain. |
| `DB_CONNECTION` | `pgsql` | Ya | Pakai `sqlite` bawaan. Aplikasi akan membaca file database yang tidak ada, dan muncul error `database ... not found` pada halaman mana pun yang menyentuh data. |
| `DATABASE_URL` | `postgresql://SIMRS:...@ep-xxx-pooler.us-east-2.aws.neon.tech/neondb?sslmode=require` | **Ya, wajib** | `SQLSTATE[08006] Connection refused` atau `could not translate host name`. Lara juga jatuh ke `DB_HOST`/`DB_PORT` yang tidak disediakan Vercel. |
| `SESSION_DRIVER` | `database` | Ya | Kalau kosong/tidak diisi, Laravel memakai `database` secara bawaan - aman. Tapi isi eksplisit supaya tidak berubah bila bawaan berubah. Yang **harus dihindari** adalah `file`: user akan logout terus. |
| `CACHE_STORE` | `database` | Ya | Sama seperti session. Defaults Laravel juga `database`, tapi isi eksplisit. Hindari `file` karena hilang tiap function di-restart. |
| `SESSION_SECURE_COOKIE` | `true` | Ya | Cookie terkirim lewat HTTP dan bisa dicegat. Vercel sudah menyediakan HTTPS, jadi selalu `true`. |
| `LOG_CHANNEL` | `stderr` | **Ya, wajib** | Default Laravel adalah `stack`/`single`, menulis ke `storage/logs/laravel.log`. Di Vercel folder itu **read-only**, jadi setiap penulisan log jadi error 500. Dengan `stderr`, log muncul di dashboard Vercel. |
| `QUEUE_CONNECTION` | `sync` | Ya | Tidak ada job antrean di aplikasi ini, jadi `sync` benar. Kalau diisi `database`, job menumpuk tanpa pernah diproses karena tidak ada worker di Vercel. |
| `VIEW_COMPILED_PATH` | `/tmp/views` | **Ya, wajib** | Blade mencoba menulis ke `storage/framework/views` yang read-only. Hasilnya: `unable to write file .../storage/framework/views/xxxx.php` pada setiap halaman yang me-render view. Ini variabel **paling sering terlupakan**. |
| `PORT` | - | **Jangan diisi** | Disuntikkan platform. Tidak perlu dan tidak boleh diisi manual. |

Variabel `VIEW_COMPILED_PATH` dan seluruh `APP_*_CACHE` juga sudah ditulis di
`vercel.json` sebagai pengaman, jadi aplikasi tetap jalan walau salah satu
kewalahan diisi di dashboard. Tapi **isi tetap di dashboard** supaya jelas
dokumentasinya dan tidak bergantung pada `vercel.json`.

### 4.3 APP_KEY: JANGAN pakai "generate Value" milik Vercel

Vercel menyediakan tombol **Generate Value** di halaman itu. Untuk `APP_KEY`
itu **berbahaya**.

**Caranya generate yang benar**, di mesin lokal:

```bash
cd /path/ke/backend
php artisan key:generate --show
```

Outputnya punya bentuk:

```
base64:AbCdEfGhIjKlMnOpQrStUvWxYz0123456789ABCDEFGHIJKLMN=
```

Salin **persis** termasuk awalan `base64:`.

**Kenapa generate Value Vercel merusak aplikasi:**

1. Tombol itu menghasilkan string acak ** polos** seperti
   `A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8`.
2. Laravel tidak memperlakukannya sebagai base64. `config/app.php` punya
   `'cipher' => 'AES-256-CBC'`, yang butuh kunci **32 byte**. Nilai polos
   36 karakter itu ditafsirkan sebagai teks mentah, bukan hasil decode
   base64.
3. Saat membuat `Encrypter`, Laravel memanggil
   `openssl_cipher_iv_length('AES-256-CBC')` dan membutuhkan tepat 32 byte. Karena
   panjang salah, ia melempar exception dengan pesan persis:
   `Unsupported cipher or incorrect key length`.
6. Exception itu terjadi **sebelum** kode aplikasi berjalan, jadi **setiap**
   halaman error 500 - termasuk halaman login. Tidak ada yang bisa dilakukan
   dari sisi kode.
4. Memperbaikinya berarti deploy ulang. Untuk data klinis, mengacak ulang
   `APP_KEY` juga membuat semua user **langsung logout** dan seluruh nilai
   yang tersimpan terenkripsi menjadi tidak terbaca.

Aturan singkat: **Generate Value Vercel boleh dipakai untuk variabel biasa
(secret, API key), TIDAK untuk `APP_KEY`.**

### 4.4 Contoh urutan pengisian

1. Buat proyek dulu di dashboard (atau `vercel link`).
2. Settings > Environment Variables > Add.
3. Isi satu per satu dari tabel di atas.
4. Deploy **setelah** semua diisi. Jangan klik redeploy setengah jalan -
   `APP_KEY` yang belum ada akan menggagalkan build check.
---

## 5. Migrasi dan seeding - MANUAL, SEBELUM deploy pertama

**Tidak ada boot hook di Vercel.** `docker/entrypoint.sh` hanya dipakai di jalur
Docker/Render, dan `RUN_MIGRATIONS` / `SEED_DEMO_DATA` di `.env.production.example`
bagian 14 **tidak berlaku** di sini. Tidak ada container yang start, tidak ada
cron, tidak ada shell.

Artinya: **kamu** yang menjalankan migrasi, dari mesin lokal, langsung
menunjuk ke Neon.

### 5.1 Cara aman: file `.env.production` sementara

Jangan pernah menimpa `.env` lokal - itu dipakai untuk pengembangan. Buat file
khusus:

```bash
cd /path/ke/backend
cp .env.production.example .env.production
```

Buka `.env.production`, lalu isi:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...          # hasil php artisan key:generate --show
APP_URL=https://simrs-ews-app.vercel.app

DB_CONNECTION=pgsql
DATABASE_URL=postgresql://SIMRS:PASSWORD_ANDA@ep-xxx-pooler.us-east-2.aws.neon.tech/neondb?sslmode=require
# PENTING: baris DB_* (DB_HOST, DB_PORT, ...) boleh dibiarkan, tapi
# DATABASE_URL di atas harus terisi. Kalau DATABASE_URL terisi, DB_* diabaikan.

SESSION_DRIVER=database
CACHE_STORE=database
SESSION_SECURE_COOKIE=true
LOG_CHANNEL=stderr
QUEUE_CONNECTION=sync
VIEW_COMPILED_PATH=/tmp/views
```

> `.env.production` sudah masuk `.gitignore`, jadi aman tidak ikut ter-commit.
> Pastikan juga file itu tidak ikut ter-upload - `.vercelignore` sudah memblokirnya.

### 5.2 Jalankan migrasi

```bash
php artisan config:clear
php artisan migrate --force
```

Lihat outputnya sampai selesai dan **tidak ada error** sebelum lanjut. Kalau
ada yang gagal, perbaiki dulu - jangan deploy ke database yang setengah jadi.

### 5.3 Jalankan seeding data demo

Hanya untuk demo, dan **sekali saja**:

```bash
php artisan db:seed --force
```

Seeder yang tersedia ada di `database/seeders/`: `UserSeeder`,
`PatientEncounterSeeder`, `ObservationSeeder`, `AbgResultSeeder`,
`SupportResultSeeder`. Semuanya dipanggil dari `DatabaseSeeder`.

Seeder data klinis tidak idempoten total: menjalankannya dua kali pada
database yang sudah berisi data nyata berisiko menduplikasi baris. Karena itu
jalankan **sekali**, dan jangan ulangi kecuali database masih benar-benar
kosong.

Kalau hanya butuh akun login tanpa data demo, jalankan:

```bash
php artisan db:seed --class=UserSeeder --force
```

### 5.4 Verifikasi

```bash
php artisan migrate:status
```

Kalau semua baris `[OK]` dan tidak ada `[Pending]`, database siap.

---

## 6. Deploy

```bash
vercel                 # deploy ke preview
```

First deploy akan/mencetak URL preview. Setelah itu:

```bash
vercel --prod          # deploy ke production
```

Lalu **perbarui `APP_URL`** di dashboard Vercel dengan URL production yang
cetak, lalu deploy ulang satu kali lagi. Vercel menyediakan otomatis domain
`https://<nama-proyek>.vercel.app` untuk Hobby.

Setelah deploy pertama, untuk deploy berikutnya cukup:

```bash
vercel --prod
```

### 6.1 Lihat log

Di dashboard: tab **Logs** > **Runtime Logs**. Di situ kelihatan error PHP,
termasuk pesan error Laravel yang sebenarnya. Dengan `LOG_CHANNEL=stderr`
seluruh log aplikasi muncul di sini.

---

## 7. Cara aset frontend disajikan

`@vite` di `resources/views/app.blade.php` membaca
`public/build/manifest.json` lalu menghasilkan URL seperti:

```html
<link rel="stylesheet" href="/build/assets/app-4f3a2b1c.css">
<script type="module" src="/build/assets/app-9d8e7c6f.js"></script>
```

Di Render dan Docker, berkas itu dilayani nginx dari `public/`. Di Vercel
tidak ada lapisan statis seperti itu, jadi:

1. `vercel.json` hanya mendaftarkan **satu** function: `api/index.php`, dengan
   `rewrites` yang mengirim semua path ke sana.
2. `vercel-php` membungkus **seluruh** isi project - termasuk `public/build` -
   ke dalam satu function./runtime php built-in server dengan `api/index.php`
   sebagai router.
3.Router `api/index.php` yang ada di repo ini memeriksa apakah request itu asking
   file asli di `public/`. Kalau ada (misal `/build/assets/app-4f3a2b1c.css`),
   file itu dikirim apa adanya dengan `Content-Type` yang benar. Kalau tidak,
   request diteruskan ke `public/index.php` milik Laravel.

Konsekuensinya:

- Hashed asset di `/build/` diberi `Cache-Control: public, max-age=31536000,
  immutable` karena nama berkasnya berbeda tiap build.
- Aset lain (favicon, robots.txt) memakai `public, max-age=3600`.
- Aset **tidak** dilayani CDN Vercel, tapi dilayani function yang sama. Untuk
  demo ini cukup; untuk produksi dengan trafik besar, lebih baik pindah ke
  Railway/Render.

Karena itu `public/build` **wajib** ikut ter-upload. Sekarang Vercel yang
membuild-nya lewat `buildCommand` (bagian 3), jadi tidak ada langkah manual
yang bisa terlupa. Kalau `public/build` tidak ada di dalam function, itu berarti
`buildCommand` gagal - bukan kamu lupa build di lokal - dan deployment itu sudah
gagal sejak build, bukan diam-diam tampil tanpa CSS.

---

## 8. Batasan paket Hobby yang perlu kamu tahu

| Batas | Arti buat aplikasi ini |
|---|---|
| **Non-komersial / pribadi** | Hobby gratis hanya untuk penggunaan non-komersial. Ini aplikasi demo internal, jadi aman. Jangan pakai untuk layanan berbayar. |
| **Functions idle-out** | Function yang tidak dipanggil ikut "dingin". Navega pertama setelah idle bisa **jumlah detik** lebih lambat. Ini bukan bug. Untuk hindari di demo, buka aplikasinya 5-10 menit sebelum memamerkannya. |
| **Tidak ada filesystem persisten** | Semua hilang saat function di-restart. Karena itu session dan cache **wajib** `database`, dan file upload pengguna **tidak bisa** disimpan lokal (pakai S3 kalau nanti perlu). |
| **Tidak ada SSH / shell** | Tidak bisa `ssh` ke server. `php artisan tinker` tidak tersedia. Semua perintah harus dijalankan dari mesin lokal. |
| **Timeout function 60 detik** | Query yang sangat lambat akan putus. `maxDuration` di `vercel.json` sudah diisi 60. |
| **Memory 1024 MB** | Memori function dibatasi. `vercel.json` sudah meminta 1024 MB. |
| **Tanpa preview deployment ke database** | Preview/preview URL memakai environment variables yang sama dengan production. Hati-hati saat menguji preview: ia menyentuh database production. |

---

## 9. Troubleshooting

Buka **Logs > Runtime Logs** di dashboard Vercel. Hampir semua pesan di bawah
terlihat di sana, lengkap dengan stack trace.

### 9.1 `@vite manifest not found` atau halaman tanpa CSS

**Gejala:** halaman terbuka, HTML keluar, tapi tanpa gaya. Di log:
`Unable to locate file in Vite manifest`.

**Penyebab:** `public/build/manifest.json` tidak ikut ter-upload. Sekarang itu
hanya bisa terjadi kalau **`buildCommand` gagal** - bukan karena `npm run build`
terlupa di mesin lokal, karena tidak ada lagi langkah lokal sama sekali
(bagian 3).

**Perbaikan, urut dari yang paling mungkin:**

1. Buka **Build Logs** di dashboard Vercel, bukan Runtime Logs. Kalau baris
   terakhir memuat `FATAL: buildCommand did not produce
   public/build/manifest.json`, berarti Vite build-nya yang bermasalah - baca
   error Vite tepat di atasnya. Perbaiki sumbernya, lalu deploy ulang.
2. Kalau build **berhasil** tapi symptoms-nya masih ada, cek `.vercelignore`
   **tidak** punya baris `/public/build`, dan `vercel.json` **tidak** punya
   `excludeFiles` di `functions` yang menutupi `public/build`.
3. Kalau `buildCommand` sama sekali tidak muncul di Build Logs, berarti
   deployment itu tidak memakai `vercel.json` repo ini. Cek Root Directory di
   Project Settings Vercel - harus menunjuk folder `backend` ini.

> Menjalankan `npm run build` secara lokal **tidak** memperbaiki masalah ini,
> karena `public/build` memang tidak ikut ter-upload ke function. Build log
> Vercel satu-satunya sumber kebenaran.

### 9.2 Halaman kosong / 500 tanpa pesan

**Periksa berurutan:**

1. `APP_KEY` ada dan berawalan `base64:` (lihat 9.4).
2. `APP_DEBUG` = `false` yang membuat error tertutup rapi. Untuk **debug
   singkat**, ubah ke `true`, deploy ulang, baca pesan di Runtime Logs, lalu
   kembalikan ke `false` sebelum hari-H. Ingat: `APP_DEBUG=true` membocorkan
   nilai environment ke browser.
3. Buka Runtime Logs, cari baris pertama yang berwarna merah.

### 9.3 `Unsupported cipher or incorrect key length`

**Penyebab:** `APP_KEY` diisi tanpa awalan `base64:` - biasanya karena memakai
tombol **Generate Value** di dashboard Vercel.

**Perbaikan:**

```bash
php artisan key:generate --show
```

Salin outputnya **termasuk** `base64:`, tempel ke `APP_KEY` di dashboard,
deploy ulang. Penjelasan lengkap ada di bagian 4.3.

### 9.4 `View [...] not found` atau `unable to write file .../views`

**Penyebab (kalau amanya `unable to write file`):** `VIEW_COMPILED_PATH` belum
diisi, sehingga Blade mencoba menulis ke `storage/framework/views` yang
read-only.

**Perbaikan:** isi `VIEW_COMPILED_PATH=/tmp/views` di dashboard (bagian 4.2).
Nilai yang sama sudah ada di `vercel.json`, jadi kalau ini masih terjadi,
periksa tidak ada salah ketik di nama variabel.

**Penyebab (kalau amanya `View [...] not found`):** nama view salah, atau
`APP_ENV` bukan `production` sehingga folder view berbeda. Pastikan
`APP_ENV=production` dan `VIEW_COMPILED_PATH` menunjuk ke `/tmp/views`
(foldernya boleh kosong - Laravel akan membuatnya sendiri saat pertama
dipakai).

### 9.5 `Class '...' not found` atau `Failed opening required .../vendor/autoload.php`

**Penyebab:** `composer install` tidak berjalan di build step.

**Perbaikan:** pastikan `composer.json` dan `composer.lock` ikut ter-upload
(keduanya **tidak** boleh ada di `.vercelignore`), lalu deploy ulang. Lihat
Build Logs di dashboard - akan terlihat `Installing Composer dependencies`.
`vendor/` sendiri **tidak** ikut ter-upload secara sengaja; runtime yang
membuild-nya.

### 9.6 `SQLSTATE[08006] Connection refused` atau `could not translate host name`

**Penyebab:** `DATABASE_URL` salah atau kosong.

**Perbaikan:**

1. Cek `DB_CONNECTION=pgsql`.
2. Cek `DATABASE_URL` di dashboard - salin ulang dari Neon **Connection
   details** > **Pooled connection**.
3. Pastikan password tidak mengandung karakter yang perlu URL-encode (`#`,
   `@`, `/`, `:` harus ditulis sebagai `%XX`). Kalau iya, encode **sekali saja**.
4. Pastikan `?sslmode=require` ada di akhir string.
5. Deploy ulang. Mengubah env var di Vercel **membutuhkan deploy ulang** -
   perubahan tidak berlaku instan.

### 9.7 404 di semua route

**Gejala:** semua URL, termasuk `/`, mengembalikan 404; atau muncul halaman
"Vercel" / Function tidak ditemukan.

**Penyebab:** entry point salah rutenya.

**Perbaikan:** pastikan `vercel.json` memuat berdua hal ini:

```json
"functions": { "api/index.php": { "runtime": "vercel-php@0.7.4", ... } },
"rewrites":  [ { "source": "/(.*)", "destination": "/api/index.php" } ]
```

Kalau `rewrites` hilang, `/` memang akan 404 karena tidak ada file bernama `/`.
Cek juga `Build Logs` - kalau build gagal, function memang tidak pernah
ter-deploy.

### 9.8 Error filesystem read-only

**Gejala:** `EROFS: read-only file system`, atau permission denied pada
storage.

**Perbaikan:** pastikan keempat hal ini benar.

1. `VIEW_COMPILED_PATH=/tmp/views`.
2. `LOG_CHANNEL=stderr` - kalau `stack`, Monolog menulis ke
   `storage/logs/laravel.log` yang read-only.
3. `SESSION_DRIVER=database` - `file` menulis ke `storage/framework/sessions`.
4. `CACHE_STORE=database` - `file` menulis ke `storage/framework/cache/data`.

Semuanya selalu bisa ditulis ke `/tmp`, jadi jangan arahkan path tulis
keluar dari sana.

### 9.9 Halaman sangat lambat saat pertama dibuka

Itu **normal** di Hobby: function yang menganggur akan mati lalu harus start
lagi. Buka aplikasinya beberapa menit sebelum demo.

---

## 10. Cara kembali ke Docker / Render

Tidak ada yang perlu dibatalkan. Semua file jalur Vercel berdiri sendiri:

```bash
rm -rf api vercel.json .vercelignore
git checkout .env.production.example   # kalau hanya ingin buang bagian Vercel
```

Yang **tidak pernah disentuh** oleh jalur Vercel:

- `Dockerfile`
- `docker/` (termasuk `entrypoint.sh`)
- `render.yaml`
- `railway.json`
- `docker-compose.yml` dan `docker-compose.pgsql.yml`
- `bootstrap/app.php`, `config/`, `app/`, `routes/`, `database/`, `resources/`

Jadi deploy ulang ke Render hanya perlu:

```bash
# Render Blueprint
render.yaml
```

atau lewat Docker:

```bash
docker build -t simrs-ews .
docker run --env-file .env.production -p 8080:8080 simrs-ews
```

Kembali ke Railway juga sama, lewat `railway.json` dan
`scripts/deploy-railway.sh`.

---

## 11. Ringkasan

| Pertanyaan | Jawaban |
|---|---|
| PHP versi berapa? | 8.3, lewat `vercel-php@0.7.4` |
| Ada `pdo_pgsql` dan `mbstring`? | Ya, keduanya ada di build 8.3 |
| Migrasi jalan otomatis? | **Tidak**. Jalankan manual dari lokal |
| Seeder jalan otomatis? | **Tidak**. Jalankan manual dari lokal |
| `config:cache` aman? | **Tidak** - semua cache diarahkan ke `/tmp` |
| Aset frontend | Dibangun Vercel sendiri lewat `buildCommand`, disajikan `api/index.php`. Tidak ada langkah build manual |
| `.env` ikut ter-upload? | **Tidak**, diblokir `.vercelignore` |
| Perlu Docker? | **Tidak**, Vercel tidak butuh Docker sama sekali |