<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute web
|--------------------------------------------------------------------------
|
| Wave ini hanya mendaftarkan rute autentikasi. Halaman login dirender sebagai
| Blade (bukan SPA) sehingga `guest` middleware di sini mencegah pengguna yang
| sudah login melihat form login lagi.
|
| Rute halaman klinis (daftar pasien + 6 modul) akan ditulis ulang pada wave
| berikutnya; jangan ditambahkan sebagai placeholder di sini.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');
Route::get('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout.get');

/*
 * `/` tidak punya halaman sendiri di phase1, jadi rute ini hanya pintu masuk: pengguna yang sudah login langsung ke Daftar Pasien, tamu ke form login.
 *
 * Dipakai 302 (bukan 301/308) supaya tujuan pengalihan masih bisa diubah tanpa harus membersihkan cache browser yang sudah terlanjur menyimpan 301.
 */
Route::get('/', fn () => auth()->check() ? redirect()->route('pasien') : redirect()->route('login'));

/*
|--------------------------------------------------------------------------
| Loader Rute Halaman SPA — routes/pages.*.php
|--------------------------------------------------------------------------
|
| KONVENSI TIM (dipakai untuk menghindari lost-write race saat 4 developer
| bekerja paralel):
|
|   1. Setiap developer halaman SPA membuat FILENYA SENDIRI,bernama
|      routes/pages.<name>.php — contoh: routes/pages.pasien.php
|   2. Developer halaman DILARANG touching routes/web.php sama sekali.
|      Jangan menambah Route:: di web.php; semuanya lewat file pages.*.php.
|   3. Nama file WAJIB mengikuti halaman yang didaftarkannya, agar tidak ambigu.
|   4. Rute page wajib (lihat docs/FRONTEND_CONTRACT.md §1.2 dan
|      docs/PAGE_ROUTE_CONVENTION.md):
|
|        routes/pages.pasien.php     -> name "pasien"      GET /pasien
|        routes/pages.profil.php     -> name "profil"      GET /encounters/{encounter}/profil
|        routes/pages.cppt.php       -> name "cppt"        GET /encounters/{encounter}/cppt
|        routes/pages.penunjang.php  -> name "penunjang"   GET /encounters/{encounter}/penunjang
|        routes/pages.farmasi.php    -> name "farmasi"     GET /encounters/{encounter}/farmasi
|        routes/pages.observasi.php  -> name "observasi"   GET /encounters/{encounter}/observasi
|        routes/pages.bundles.php    -> name "bundles"     GET /encounters/{encounter}/bundles
|
|      {encounter} di URL adalah STRING encounters.encounter_id
|      (mis. enc-159853-icu-20260906), BUKAN id numerik.
|
| Sifat loader:
|
|   - Berkas discovered lewat glob(__DIR__.'/pages.*.php') lalu di-sort() agar
|     urutan registrasi deterministik antar request. Kalau belum ada satu pun
|     file pages.*.php, glob mengembalikan array kosong dan loop tidak jalan
|     sama sekali — aplikasi tetap normal.
|   - Pakai require (bukan require_once), sama seperti pemuatan routes/web.php
|     oleh Laravel, sehingga file baru dari developer langsung terbaca pada
|     request berikutnya tanpa rebuild atau restart. Tidak ada cache route di
|     sini.
|   - Tolerant terhadap file yang belum selesai ditulis developer paralel:
|     setiap require dibungkus try/catch (ParseError|Throwable) sehingga satu
|     file rusak hanya di-skip + dicatat via Log::warning(), tidak menjatuhkan
|     seluruh aplikasi.
|
*/

$pageRouteFiles = glob(__DIR__.'/pages.*.php') ?: [];
sort($pageRouteFiles, SORT_STRING);

foreach ($pageRouteFiles as $pageRouteFile) {
    if (! is_file($pageRouteFile)) {
        continue;
    }

    try {
        require $pageRouteFile;
    } catch (ParseError|Throwable $e) {
        Log::warning('routes/pages.*.php gagal dimuat, file ini dilewati.', [
            'file' => basename($pageRouteFile),
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
        ]);
    }
}
