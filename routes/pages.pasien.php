<?php

use App\Http\Controllers\PasienController;
use Illuminate\Support\Facades\Route;

/*
 * Halaman "Daftar Pasien" (dimuat oleh loader routes/pages.*.php di web.php).
 *
 * Sengaja TIDAK memakai Inertia: halaman ini dirender server sebagai Blade
 * (resources/views/pasien/index.blade.php) dan setiap kartu melakukan full
 * page load ke route "profil". Lihat docs/FRONTEND_CONTRACT.md 1.2 dan
 * docs/PAGE_ROUTE_CONVENTION.md.
 *
 * Middleware `auth` (+ redirectGuestsTo(route('login')) di bootstrap/app.php)
 * sudah menangani tamu: GET /pasien tanpa session -> 302 ke /login.
 *
 * POST /pasien (name "pasien.store") menyimpan admisi baru dari modal
 * "Tambah Pasien" dan dialihkan ke halaman profil episode yang baru dibuat.
 * Rute ini memakai alias `role` yang sudah terdaftar di bootstrap/app.php.
 */

Route::middleware('auth')->group(function () {
    Route::get('/pasien', [PasienController::class, 'index'])->name('pasien');

    /*
     * PENULISAN admisi hanya untuk dokter (docs/AUTHORIZATION.md): admisi baru
     * berarti menulis ASMED + diagnosa + prosedur, dan ketiganya dibatasi di
     * matriks itu ke peran dokter. GET /pasien sengaja TIDAK diberi `role`
     * supaya perawat dan bidan tetap bisa membuka census seperti sebelumnya.
     */
    Route::post('/pasien', [PasienController::class, 'store'])
        ->middleware('role:dokter')
        ->name('pasien.store');
});
