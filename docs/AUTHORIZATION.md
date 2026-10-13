# Matriks Otorisasi

SIMRS RSP Rotinsulu memakai tiga peran saja: `perawat`, `bidan`, dan `dokter`
(`App\Enums\UserRole`). Otorisasi sengaja tidak memakai spatie/laravel-permission
karena kebutuhan granular per-action tidak ada; cukup satu kolom `role` dan
helper `User::hasRole()`.

Dokumen ini adalah **baseline untuk wave berikutnya**. Belum ada yang
di-enforce di middleware untuk modul klinis. `RequireRole` sudah siap dan
terdaftar sebagai alias `role`, tetapi rute SPA belum memakai middleware itu.

## Matriks

| Kemampuan                                     | Perawat | Bidan | Dokter |
|-----------------------------------------------|:-------:|:-----:|:------:|
| Daftar / cari pasien                          |   Ya    |  Ya   |   Ya   |
| Lihat encounter aktif dan riwayat tas        |   Ya    |  Ya   |   Ya   |
| **Input observasi vital + Skor EWS**          |   Ya    |  Ya   |   Ya   |
| Isi bundel asuhan (serial, vom, GCS, pupils)  |   Ya    |  Ya   |   Ya   |
| **Asuhan keperawatan**                         |   Ya    |  Ya   |   Ya   |
| **Administrasi obat** (dosis, rute, waktu)    |   Ya    |  Ya   |   Ya   |
| Rekap dosis harian                            |   Ya    |  Ya   |   Ya   |
| Device invasive / ABG / penunjang             |   Ya    |  Ya   |   Ya   |
| **ASMED** (anamnesis, pemeriksaan fisik)      |    -    |   -   |   Ya   |
| **Diagnosis**                                 |    -    |   -   |   Ya   |
| **Prosedur / tindakan**                       |    -    |   -   |   Ya   |
| **CPPT**                                      |    -    |   -   |   Ya   |
| Tutup encounter (discharge)                   |    -    |   -   |   Ya   |
| Edit rekam medis yang sudah ditutup           |    -    |   -   |   Ya   |

Semua peran **membaca** apa pun yang dibutuhkan untuk konteks shift-nya. Perawat
dan bidan boleh melihat ASMED, diagnosis, dan prosedur yang sudah diisi dokter
karena itu bagian dari konteks asuhan. Dokter melihat semuanya. Yang dibatasi
adalah **menulis**, bukan membaca.

## Jejak audit (UU PDP Pasal 26)

Karena membaca tidak dibatasi, setiap akses terautentikasi ke modul klinis
dicatat di tabel `access_logs` oleh `App\Http\Middleware\LogRecordAccess`:
siapa, data pasien mana (`encounter_id` + `patient_id`), modul apa, baca
atau tulis, kapan. Hanya metadata — tidak pernah isi rekam medis.

Aturan tabel: append-only (tanpa `updated_at`, tanpa endpoint hapus),
tanpa foreign key (log harus selamat dari penghapusan data yang dirujuk),
dan kegagalan tulis ditelan supaya audit tidak pernah mengubah hasil klinis.
Retensi mengikuti kebijakan RS (patokan umum 3 tahun); penghapusan hanya
lewat job terjadwal.

Akun `perawat` / `bidan` / `dokter` di bawah adalah akun **demo untuk trial
klien** — disengaja ada, bukan kebocoran. Passwordnya tidak dicantumkan di
sini; seeder menolak berjalan di production dan kotak kredensial di halaman
login disembunyikan otomatis di production.

## Cara memasang middleware

```php
// boleh semua petugas
Route::get('/observasi', ObservasiController::class)
    ->middleware('role:perawat,bidan,dokter');
Route::post('/observasi', ObservasiStoreController::class)
    ->middleware('role:perawat,bidan,dokter');

// khusus dokter
Route::get('/asmed', AsmedController::class)->middleware('role:dokter');
```

Perilaku `app/Http/Middleware/RequireRole.php`:

- Belum login: melempar `AuthenticationException`, lalu middleware `auth` bawaan
  yang mengarahkan tamu ke `route('login')` (didaftarkan di `bootstrap/app.php`
  melalui `redirectGuestsTo`).
- Sudah login tetapi peran salah: `abort(403, 'Anda tidak memiliki akses ke modul
  ini.')`, dirender sebagai halaman error berbahasa Indonesia di
  `resources/views/errors/403.blade.php`.

## Catatan klinis

- **Perawat dan bidan** dianggap setara untuk modul klinis. Bedanya hanya konteks
  praktik di luar aplikasi (perawat: infus dan monitor ICU; bidan: persalinan),
  dan itu sudah tercermin di kolom `users.specialty`.
- Tidak ada peran admin. Perubahan akun hanya lewat `php artisan tinker` atau
  seeder.
- Bila suatu modul butuh pembatasan lebih ketat (misalnya hanya DPJP boleh menutup
  encounter), itu diputuskan per-modul ketika rute-nya ditulis, bukan di level
  peran.

## Akun demo (khusus pengembangan lokal)

| Username    | Nama              | specialty          |
|-------------|-------------------|--------------------|
| `perawat`   | Ns. Tri Handayani | Perawat ICU        |
| `bidan`     | Bd. Sari Wulandari | Bidan              |
| `dokter`    | dr. Rangga Saputra | Konsultan Intensif |

Password default hanya untuk seed lokal (`database/seeders/UserSeeder.php`)
dan TIDAK dicantumkan di sini. Seeder menolak berjalan di production;
akun produksi wajib memakai password yang diganti manual. Kotak kredensial
di halaman login juga disembunyikan otomatis di production.