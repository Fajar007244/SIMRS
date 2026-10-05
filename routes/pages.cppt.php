<?php

use App\Http\Controllers\CpptController;
use App\Models\Encounter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Halaman "Medis & CPPT"
|--------------------------------------------------------------------------
|
| Satu GET baca + LIMA POST tulis di bawah. Kelima POST memakai path yang sama
| dengan GET (/encounters/{encounter}/cppt/<suffix>) supaya tidak ada rute lain
| yang perlu didaftarkan di resources/js/router.js - file itu beku, jadi URL
| tulis dibentuk di sisi halaman dari url('cppt', ...) + suffix.
|
| MATRIKS OTORISASI (docs/AUTHORIZATION.md): semua role boleh MEMBACA modul
| klinis; yang dibatasi adalah menulis.
|   - ASMED, diagnosa, prosedur, CPPT   -> dokter saja
|   - asuhan keperawatan / kebidanan     -> perawat + bidan + dokter
| Alias `role` sudah terdaftar di bootstrap/app.php. Peran yang salah
| mendapat 403, bukan 500.
|
| {encounter} adalah STRING `encounters.encounter_id`
| (mis. enc-159853-icu-20260906), BUKAN primary key numerik, jadi
| getRouteKeyName() tidak bisa dipakai: model Encounter milik developer lain
| (file beku) dan tidak boleh disentuh. Karena itu binding ditulis eksplisit di
| bawah, dan sengaja didaftarkan ulang ( Closure-nya identik, last-write-wins)
| oleh routes/pages.penjunjang.php supaya tiap file rute tidak bergantung pada
| urutan pemuatan routes/web.php.
|
*/

Route::bind('encounter', function (string $value) {
    return Encounter::query()
        ->where('encounter_id', $value)
        ->first()
        ?? abort(404, 'Episode perawatan tidak ditemukan. Periksa kembali kode encounter pada alamat halaman.');
});

Route::middleware(['auth', 'role:perawat,bidan,dokter'])
    ->get('/encounters/{encounter}/cppt', [CpptController::class, 'show'])
    ->name('cppt');

/*
 * LIMA endpoint tulis modul CPPT.
 *
 * `auth` + `role:` diulang eksplisit pada setiap rute (bukan pada group)
 * karena matriks otorisasinya berbeda-beda: asuhan keperawatan dibuka untuk
 * perawat dan bidan, empat modul lain hanya untuk dokter.
 */
Route::middleware('auth')->group(function () {
    Route::post('/encounters/{encounter}/cppt/note', [CpptController::class, 'storeNote'])
        ->middleware('role:dokter')
        ->name('cppt.note.store');

    Route::post('/encounters/{encounter}/cppt/asmed', [CpptController::class, 'storeAsmed'])
        ->middleware('role:dokter')
        ->name('cppt.asmed.store');

    Route::post('/encounters/{encounter}/cppt/nursing', [CpptController::class, 'storeNursingCare'])
        ->middleware('role:perawat,bidan,dokter')
        ->name('cppt.nursing.store');

    Route::post('/encounters/{encounter}/cppt/diagnosis', [CpptController::class, 'storeDiagnosis'])
        ->middleware('role:dokter')
        ->name('cppt.diagnosis.store');

    Route::post('/encounters/{encounter}/cppt/procedure', [CpptController::class, 'storeProcedure'])
        ->middleware('role:dokter')
        ->name('cppt.procedure.store');
});
