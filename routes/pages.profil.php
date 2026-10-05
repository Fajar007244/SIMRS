<?php

use App\Http\Controllers\ProfilController;
use App\Models\Encounter;
use Illuminate\Support\Facades\Route;

/*
 * Halaman "Profil & Ringkasan" (dimuat loader routes/pages.*.php di web.php).
 *
 * {encounter} adalah STRING encounters.encounter_id, mis.
 * enc-159853-icu-20260906 - BUKAN primary key numerik. URL dengan angka polos
 * seperti /encounters/1/profil harus menghasilkan 404.
 *
 * KENAPA Route::bind() DAN BUKAN getRouteKeyName() DI MODEL
 * -------------------------------------------------------
 * 1. app/Models/** milik developer services dan dipakai beberapa wave
 *    paralel; menambah accessor route key di sana berisiko bentrok tulis.
 * 2. Binder di sini berlaku global untuk kunci "encounter", jadi modul lain
 *    (cppt / penunjang / farmasi / observasi / bundles) otomatis memakai
 *    semantik yang sama tanpa mengulang closure yang sama.
 * 3. Loader melakukan glob() lalu sort() naik, jadi pages.profil.php dimuat
 *    paling akhir dan binder ini yang menang bila developer lain ikut
 *    mendaftarkannya. Konsekuensi: jangan mendaftarkan binder "encounter"
 *    dengan semantik berbeda, atau pindahkan blok ini ke file terawal.
 *
 * abort(404, ...) dipanggil langsung di dalam binder supaya pesan yang tampil
 * berbahasa Indonesia, bukan "No query results for model [App\Models\Encounter]".
 */
Route::bind('encounter', function ($value) {
    $encounter = is_string($value) && trim($value) !== ''
        ? Encounter::query()->where('encounter_id', trim($value))->first()
        : null;

    abort_unless($encounter instanceof Encounter, 404, 'Episode perawatan tidak ditemukan.');

    return $encounter;
});

Route::middleware('auth')->group(function () {
    Route::get('/encounters/{encounter}/profil', [ProfilController::class, 'show'])->name('profil');
});
