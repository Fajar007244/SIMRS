<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom yang dipakai formulir observasi phase1/observasi.html
 * tapi belum punya kolom sendiri di tabel `observations`.
 *
 * ATURAN MIGRASI INI
 *
 *  1. HANYA menambah kolom. Tidak ada kolom lama yang di-ALTER atau di-DROP,
 *     dan tidak ada kolom baru yang NOT NULL, jadi 60 baris observasi hasil
 *     seed lama (yang semua kolom barunya bernilai NULL) tetap valid tanpa
 *     perlu disentuh sama sekali.
 *
 *  2. `map` tetap dipakai apa adanya (sudah ada di create_observations_table),
 *     jadi tidak diduplikasi di sini.
 *
 *  3. Kolom yang HANYA punya bentuk tampilan di prototype disimpan sebagai
 *     string, bukan boolean:
 *        - `respiratory_problem` -> 'Tidak' / 'Ya'  (radio #gangguanParu)
 *        - `bp_method`           -> 'NIBP' / 'IBP'  (radio #metodeTD)
 *     Nilai mentah radio lebih jujur daripada boolean 0/1 karena validator
 *     ObservationController bisa menolak nilai yang tidak dikenal tanpa
 *     menebak apa maksudnya.
 *
 *  4. `rass` disimpan DUA kali: kolom integer `rass` (bisa di-query dan
 *     diurutkan) dan `ventilator_settings.rass` (lokasi lama yang sudah dibaca
 *     ObservationService::consciousnessLabel() untuk label "DPO (RASS -2)").
 *     Dua-duanya ditulis sinkron supaya baris lama maupun baru terbaca.
 *
 *  5. `gcs` yang lama tetap smallInteger dan tidak disentuh. Formulir
 *     prototype memakai kolom TEKS ("Contoh: E3-Vt-M5") karena pasien intubasi
 *     diskor per komponen, jadi verbatim-nya disimpan di `gcs_text`. Angka
 *     3-15 (bentuk lama / baris seed) tetap mengisi kolom `gcs`.
 *
 *  6. Kolom volume memakai decimal(8,1) sama seperti `intake` / `output` yang
 *     sudah ada, jadi presisi dan batasnya konsisten antar kolom cairan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            /* Section 1 - Observasi Umum */
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->string('gcs_text', 50)->nullable();
            $table->smallInteger('rass')->nullable();

            /* Section 2 - Tanda-Tanda Vital */
            $table->string('bp_method', 10)->nullable();
            $table->smallInteger('blood_glucose')->nullable();
            $table->string('respiratory_problem', 10)->nullable();

            /* Section 3 - Cairan */
            $table->string('transfusion_type', 20)->nullable();
            $table->decimal('transfusion_volume', 8, 1)->nullable();
            $table->decimal('parenteral_volume', 8, 1)->nullable();
            $table->decimal('enteral_volume', 8, 1)->nullable();
            $table->decimal('urine_volume', 8, 1)->nullable();
            $table->decimal('drain_volume', 8, 1)->nullable();
            $table->decimal('iwl_volume', 8, 1)->nullable();

            /* Section 5 - Catatan & Tindakan Keperawatan */
            $table->string('nursing_action', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropColumn([
                'weight_kg',
                'gcs_text',
                'rass',
                'bp_method',
                'blood_glucose',
                'respiratory_problem',
                'transfusion_type',
                'transfusion_volume',
                'parenteral_volume',
                'enteral_volume',
                'urine_volume',
                'drain_volume',
                'iwl_volume',
                'nursing_action',
            ]);
        });
    }
};
