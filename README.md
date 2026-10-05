# SIMRS RSP Rotinsulu

Aplikasi Sistem Informasi Manajemen Rumah Sakit untuk **Rumah Sakit
Pusat Rotinsulu**. Menyediakan pencatatan episode perawatan, CPPT, asuhan
keperawatan, instalasi farmasi, hasil penunjang, dan **observasi EWS** —
sistem peringatan dini pasien yang deteriorating.

Dibangun dengan **Laravel 12 + Inertia 2 + Vue 3**, dengan **PostgreSQL**
sebagai database produksi.

---

## 📌 Deploy

Ada **dua** jalur deploy yang didukung. Keduanya memakai `Dockerfile` yang
sama persis, jadi hasil build-nya identik.

| Jalur | Untuk | Panduan |
|---|---|---|
| **Render** (disarankan) | Hosting produksi | **[docs/RENDER.md](docs/RENDER.md)** |
| Railway | Hosting yang sudah berjalan | **[docs/RAILWAY.md](docs/RAILWAY.md)** |

> ### 🚀 Mulai di sini: **[docs/RENDER.md](docs/RENDER.md)**
>
> Panduan langkah demi langkah untuk orang pertama: membuat git repository,
> membuat database PostgreSQL, membuat Web Service dengan **Runtime: Docker**,
> mengisi **setiap** environment variable, membaca log, menjalankan migrasi
> dan seeding, memasang domain kustom, sampai **tabel troubleshooting**.
>
> Ada juga jalur pintasan: file **[render.yaml](render.yaml)** membuat
> database dan Web Service sekaligus, lengkap dengan environment variable-nya.

### 🚀 Render

Ringkasnya:

```bash
# 1. image produksi dibangun sendiri (Node + Composer + nginx + php-fpm)
docker build -t simrs-ews .

# 2. jalankan dengan PostgreSQL lokal
docker compose -f docker-compose.pgsql.yml up -d --build
docker compose -f docker-compose.pgsql.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.pgsql.yml run --rm app php artisan db:seed --force

# 3. deploy: New > Blueprint > pilih repo > Apply
```

### 🚂 Railway

Ringkasnya:

```bash
railway up
```

> Konfigurasi produksi lengkap ada di
> **[.env.production.example](.env.production.example)**. Penjelasan
> arsitektur, model data, dan aturan klinis ada di
> **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**.

---

## 🔐 Akun Demo

Tiga akun, sudah dibuat oleh `php artisan db:seed`.

| Peran | Username | Password |
|---|---|---|
| Perawat | `perawat` | `perawat123` |
| Bidan | `bidan` | `bidan123` |
| Dokter | `dokter` | `dokter123` |

> **Jangan pakai akun ini di produksi.** Buat user sendiri dan hapus data demo.
> Pandannya ada di [docs/RENDER.md §8](docs/RENDER.md#8-migrasi-dan-seeding).

---

## 📄 Tujuh Halaman

| Halaman | URL | Render | Isi |
|---|---|---|---|
| **Daftar Pasien** | `/pasien` | Blade | Daftar pasien dengan kartu ringkas; tiap kartu membuka halaman profil. |
| **Profil Pasien** | `/encounters/{id}/profil` | Inertia | Identitas, riwayat alergi, ringkasan episode, dan data rujukan. |
| **CPPT** | `/encounters/{id}/cppt` | Inertia | Catatan perkembangan perawat per hari. |
| **Penunjang** | `/encounters/{id}/penunjang` | Inertia | Hasil laboratorium, darah, mikroba, radiologi, dan analisa gas darah. |
| **Farmasi** | `/encounters/{id}/farmasi` | Inertia | Terapi obat, jadwal, dan formularium. |
| **Observasi** | `/encounters/{id}/observasi` | Inertia | Formulir observasi EWS, flowsheet, tren, koreksi pemberian obat, dan audit bundle. |
| **Bundles** | `/encounters/{id}/bundles` | Inertia | Kepatuhan bundle pencegahan infeksi dan HAIs. |

`{id}` adalah `encounters.encounter_id` berupa **string**, misalnya
`enc-159853-icu-20260906` — bukan `id` numerik.

Halaman `/pasien` sengaja dirender sebagai Blade (bukan SPA) karena tiap kartunya
melakukan *full page load* ke halaman profil. Enam halaman lainnya memakai
Inertia + Vue.

---

## 🧱 Tumpukan Teknologi

| Lapisan | Teknologi |
|---|---|
| Backend | Laravel 12.69.3, PHP `^8.2` |
| SPA bridge | Inertia.js 2.0 |
| Frontend | Vue 3.5, Vite 7 |
| Styling | Tailwind CSS 3.4 (via PostCSS) |
| Database produksi | PostgreSQL 16 |
| Database lokal | SQLite |
| Session & cache | Tabel PostgreSQL (`sessions`, `cache`) |
| Web server | nginx + php-fpm (dalam satu image) |
| Deploy | Render (`render.yaml` + `Dockerfile`), Railway (`railway.json`), atau Nixpacks |

---

## 🚀 Mulai Cepat (SQLite)

Prasyarat: PHP 8.2+, Composer 2, Node 20.19+ atau 22.12+.

> **Di Windows dengan XAMPP**, PHP mungkin belum ada di `PATH`. Jalankan ini dulu
> di setiap terminal baru:
>
> ```powershell
> $env:PATH = "C:\xampp\php;$env:PATH"
> ```

```bash
cd backend

composer install
npm install

cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate
php artisan db:seed            # mengisi 3 akun demo + data contoh

npm run build                  # atau: npm run dev (hot reload)
php artisan serve
```

Buka **http://localhost:8000**, lalu login dengan salah satu akun demo.

### Kalau butuh PostgreSQL (paritas dengan produksi)

```bash
docker compose -f docker-compose.pgsql.yml up -d --build
docker compose -f docker-compose.pgsql.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.pgsql.yml run --rm app php artisan db:seed --force
# http://localhost:8080
```

Jalur ini memakai `Dockerfile` yang sama dengan yang dipakai Render dan Railway, jadi
menguji di sini berarti menguji konfigurasi yang benar-benar akan deploy.

---

## ⚠️ Peringatan Klinis: Skala EWS

Aplikasi ini memakai **British Early Warning Scale 4 tingkat** —
**BUKAN** MEWS 3-tingkat.

| Total skor | Tingkat |
|---|---|
| 0 - 2 | Low |
| 3 - 4 | Medium |
| 5 - 6 | High |
| 7 - 18 | **Emergency** |

Enam parameter, masing-masing punya rentang skor 0-3, dengan **Kesadaran
(AVPU) dihitung sebagai parameter tersendiri**. Skor maksimum 18.

**Tabel lengkap ada di `config/ews.php`.** Mengubahnya tanpa keputusan tim
klinis akan langsung merusak 60 baris observasi hasil seed, karena
`ews_total` dan `ews_risk` dihitung dari tabel itu. Rinciannya ada di
[docs/DEPLOYMENT.md §6](docs/DEPLOYMENT.md#6-skala-ews).

---

## 📚 Dokumentasi Lainnya

| Dokumen | Isi |
|---|---|
| [docs/RENDER.md](docs/RENDER.md) | **Panduan deploy Render lengkap** + environment variable + troubleshooting |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Arsitektur, setup lokal, referensi env, model data, otorisasi, aturan EWS |
| [docs/AUTHORIZATION.md](docs/AUTHORIZATION.md) | Matriks hak akses per peran |
| [docs/FRONTEND_CONTRACT.md](docs/FRONTEND_CONTRACT.md) | Kontrak antara backend dan komponen Vue |
| [docs/PAGE_ROUTE_CONVENTION.md](docs/PAGE_ROUTE_CONVENTION.md) | Konvensi penamaan rute halaman |
| [.env.production.example](.env.production.example) | Referensi **seluruh** environment variable untuk produksi |

---

## 📁 Struktur Direktori

```
backend/
├── app/                     # Model, Controller, Middleware, Enum
├── bootstrap/app.php        # Rute /up, middleware, renderer error
├── config/
│   ├── ews.php              # TABEL SKOR EWS  <- baca sebelum mengubah
│   ├── hai.php              # Katalog bundle & perangkat invasif
│   └── formularium.php      # Formularium obat
├── database/migrations/     # 15 migrasi
├── database/seeders/        # 6 seeder (idempoten)
├── docker/                  # entrypoint.sh, nginx.conf, php.ini, php-fpm.d
├── docs/                    # Dokumentasi
├── resources/js/Pages/      # 7 komponen halaman Vue
├── routes/
│   ├── web.php              # Autentikasi + loader routes/pages.*.php
│   └── pages.*.php          # 7 berkas rute halaman
├── Dockerfile               # 3 tahap: assets -> vendor -> runtime
├── docker-compose.yml       # Stack lokal SQLite
├── docker-compose.pgsql.yml # Stack lokal PostgreSQL
├── render.yaml              # Blueprint Render (Postgres + Web Service)
├── nixpacks.toml            # Alternatif tanpa Docker
└── Procfile                 # Deklarasi proses untuk PaaS
```

---

## 📄 Lisensi

Proyek internal RSP Rotinsulu.