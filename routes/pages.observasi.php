<?php

use App\Http\Controllers\ObservasiController;
use App\Models\Encounter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Halaman "Observasi EWS & Hemodinamik"
|--------------------------------------------------------------------------
|
|   observasi         GET    /encounters/{encounter}/observasi
|   observasi.store   POST   /encounters/{encounter}/observasi
|   observasi.destroy POST   /encounters/{encounter}/observasi/delete
|
| Hanya tiga route: satu baca dan dua tulis. resources/js/router.js (file
| beku) sudah memuat nama `observasi`, sedangkan dua nama tulis tidak ada di
| sana; Observasi.vue membentuk URL keduanya dari url('observasi', ...) + suffix
| sehingga router.js tidak perlu diubah. Penambahan kedua nama itu ke
| router.js ROUTES adalah perubahan file beku yang dilaporkan di hasil wave
| ini, bukan yang dikerjakan di sini.
|
| SLOT JAM: ObservationService::save() bersifat UPSERT pada
| (encounter_id, observation_date, observation_time) dan mengembalikan
| {observation, created, duplicated}. `duplicated` ditampilkan sebagai
| keterangan sukses "Slot jam ini sudah ada, data diperbarui." - jadi satu
| klik Simpan untuk slot yang sudah terisi tidak pernah menggandakan baris
| dan tidak pernah memunculkan dialog browser.
|
| Matriks otorisasi (docs/AUTHORIZATION.md): "Input observasi vital + Skor
| EWS" dan "Administrasi obat" boleh untuk ketiga role, jadi ketiga route
| memakai middleware yang sama.
|
| {encounter} adalah STRING `encounters.encounter_id`, bukan primary key.
| Model Encounter tidak menimpa getRouteKeyName(), jadi Route::bind() di bawah
| yang memastikan /encounters/1/observasi menghasilkan 404, bukan
| episode pertama. Nilai yang tidak ditemukan di-abort dengan pesan
| berbahasa Indonesia, bukan 404 kosong.
|
*/

Route::bind('encounter', function (string $value) {
    return Encounter::query()
        ->where('encounter_id', $value)
        ->first()
        ?? abort(404, 'Episode perawatan tidak ditemukan. Periksa kembali kode encounter pada alamat halaman.');
});

Route::middleware(['auth', 'role:perawat,bidan,dokter'])->group(function () {
    Route::get('/encounters/{encounter}/observasi', [ObservasiController::class, 'show'])
        ->name('observasi');

    Route::post('/encounters/{encounter}/observasi', [ObservasiController::class, 'store'])
        ->name('observasi.store');

    Route::post('/encounters/{encounter}/observasi/delete', [ObservasiController::class, 'destroy'])
        ->name('observasi.destroy');
});
