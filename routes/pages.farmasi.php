<?php

use App\Http\Controllers\FarmasiController;
use App\Models\Encounter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Halaman "Farmasi & Drip Inotropik"
|--------------------------------------------------------------------------
|
|   farmasi   GET   /encounters/{encounter}/farmasi
|
| Halaman READ-ONLY: seluruh datanya dibaca dari MedicationRecapService, dan
| koreksi pemberian obat/cairan yang menjadi sumbernya dicatat pada modul
| Observasi. Tidak ada route tulis di file ini.
|
| Matriks otorisasi: "Rekap dosis harian" dan "Administrasi obat" boleh untuk
| ketiga role (docs/AUTHORIZATION.md), jadi semua role boleh membaca.
|
| {encounter} adalah STRING `encounters.encounter_id`
| (mis. enc-159853-icu-20260906), BUKAN primary key numerik. Model Encounter
| milik developer lain (file beku) sehingga getRouteKeyName() tidak boleh
| disentuh; karena itu binding ditulis eksplisit di bawah. Closure-nya
| sengaja didaftarkan ulang oleh routes/pages.cppt.php dan
| routes/pages.penjunjang.php (identik, last-write-wins) supaya tiap file
| rute tidak bergantung pada urutan pemuatan routes/web.php.
|
*/

Route::bind('encounter', function (string $value) {
    return Encounter::query()
        ->where('encounter_id', $value)
        ->first()
        ?? abort(404, 'Episode perawatan tidak ditemukan. Periksa kembali kode encounter pada alamat halaman.');
});

Route::middleware(['auth', 'role:perawat,bidan,dokter'])
    ->get('/encounters/{encounter}/farmasi', [FarmasiController::class, 'show'])
    ->name('farmasi');
