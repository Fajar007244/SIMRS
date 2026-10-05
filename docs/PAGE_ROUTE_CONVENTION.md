# Konvensi Rute Halaman SPA (`routes/pages.*.php`)

## Kenapa ada aturan ini

Beberapa developer bekerja **berparalel** di repo yang sama. Kalau semua orang
menulis rute halaman di `routes/web.php`, ada risiko *lost-write race*: satu
versi menimpa versi yang lain tanpa disadari.

## Aturan wajib

1. Setiap developer halaman SPA membuat **file-nya sendiri**:
   `routes/pages.<name>.php` — contoh `routes/pages.pasien.php`.
2. **DILARANG** mengedit `routes/web.php`. File itu milik tim inti saja.
   Jangan menambahkan `Route::` di sana; semuanya lewat file `pages.*.php`.
3. **Nama file wajib mengikuti halaman yang didaftarkannya**, agar tidak ambigu
   dan mudah di-review.
4. Loader di akhir `routes/web.php` otomatis me-`require` semua
   `routes/pages.*.php` (diurutkan). Tidak perlu mendaftarkan file baru di
   tempat lain, dan tidak perlu rebuild/restart.
5. Kalau file kamu belum syntaks-valid, loader akan **skip file itu** dan
   menulis `Log::warning()` — aplikasi tetap jalan, hanya rute file tersebut
   yang belum terdaftar. Perbaiki filenya, muat ulang, selesai.

## Route name yang wajib tersedia

Persis seperti §1.2 `docs/FRONTEND_CONTRACT.md` (`resources/js/router.js`
sudah mengasumsinya):

| route name | method | URI | param |
|---|---|---|---|
| `pasien`    | GET | `/pasien`                        | — |
| `profil`    | GET | `/encounters/{encounter}/profil`    | `encounter` |
| `cppt`      | GET | `/encounters/{encounter}/cppt`      | `encounter` |
| `penunjang` | GET | `/encounters/{encounter}/penunjang` | `encounter` |
| `farmasi`   | GET | `/encounters/{encounter}/farmasi`   | `encounter` |
| `observasi` | GET | `/encounters/{encounter}/observasi` | `encounter` |
| `bundles`   | GET | `/encounters/{encounter}/bundles`   | `encounter` |

Enam nama terakhir (**kecuali `pasien`**) memakai parameter `{encounter}`.
Nilainya adalah **STRING `encounters.encounter_id`**, contoh
`enc-159853-icu-20260906` — **BUKAN** primary key numerik.

## Pemetaan nama file

| file | route name | halaman Vue |
|---|---|---|
| `routes/pages.pasien.php`    | `pasien`    | `Pages/Pasien.vue` |
| `routes/pages.profil.php`    | `profil`    | `Pages/Profil.vue` |
| `routes/pages.cppt.php`      | `cppt`      | `Pages/Cppt.vue` |
| `routes/pages.penunjang.php` | `penunjang` | `Pages/Penunjang.vue` |
| `routes/pages.farmasi.php`   | `farmasi`   | `Pages/Farmasi.vue` |
| `routes/pages.observasi.php` | `observasi` | `Pages/Observasi.vue` |
| `routes/pages.bundles.php`   | `bundles`   | `Pages/Bundles.vue` |

## Checklist sebelum selesai

- [ ] File bernama `routes/pages.<nama-halaman>.php`
- [ ] `routes/web.php` **tidak** disentuh (cek `git status`)
- [ ] Route name persis seperti tabel di atas
- [ ] `php artisan route:list` memuat route kamu
- [ ] `vendor\bin\pint.bat --test routes` hijau