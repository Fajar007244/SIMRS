<?php

use App\Http\Controllers\BundlesController;
use App\Models\Encounter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Halaman "Bundle HAIs (VAP/CLABSI/CAUTI)"
|--------------------------------------------------------------------------
|
|   bundles   GET   /encounters/{encounter}/bundles
|
| Halaman READ-ONLY: penilaian bundle HAIs dan pencatatan perangkat invasif
| dilakukan pada formulir observasi EWS (modul Observasi), sedangkan modul ini
| hanya mengevaluasinya lewat BundleService. Tidak ada route tulis di file ini.
|
| Matriks otorisasi: "Isi bundel asuhan" dan "Device invasive" boleh untuk
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
    ->get('/encounters/{encounter}/bundles', [BundlesController::class, 'show'])
    ->name('bundles');
