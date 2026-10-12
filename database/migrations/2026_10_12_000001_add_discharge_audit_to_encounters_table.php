<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak audit untuk penutupan episode (discharge).
 *
 * KENAPA DI PERLUKAN
 *
 * `encounters.status` dan `encounters.discharged_at` sudah ada sejak awal dan
 * sudah cukup untuk menampilkan "Aktif / Selesai" beserta tanggal keluar.
 * Yang belum ada adalah jawaban untuk pertanyaan audit: siapa yang menutup
 * episode ini, kapan, dan dengan alasan apa.
 *
 * Tanpa itu, rek medis bisa kehilangan status tanpa jejak - dan di sistem
 * rumah sakit itu sumber masalah yang sulit dicari belakangan ("kenapa
 * episode saya sudah selesai?").
 *
 * ATURAN MIGRASI INI
 *
 *  1. HANYA menambah kolom. Tidak ada kolom lama yang di-ALTER atau di-DROP,
 *     jadi semua episode yang sudah ada tetap valid tanpa disentuh. Kolom
 *     lama nullable, dan baris yang sudah `status = 'selesai'` sebelum
 *     migrasi ini tetap punya discharged_at seperti sebelumnya.
 *
 *  2. `discharge_destination` disimpan apa adanya sebagai string, bukan
 *     enum database, supaya menambah pilihan baru cukup mengubah
 *     App\Enums\DischargeDestination tanpa perlu migrasi lagi. Daftar nilai
 *     yang sah divalidasi di lapisan PHP.
 *
 *  3. Tidak ada kolom terpisah untuk waktu penutupan: waktu itu sudah
 *     ada di discharged_at. Yang ditambahkan hanya AKTOR dan alasan, yang
 *     memang tidak ada di kolom mana pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table) {
            // User yang menutup episode. NULL untuk episode yang selesai
            // sebelum migrasi ini ada, atau yang ditutup di luar aplikasi.
            $table->unsignedBigInteger('discharged_by')->nullable();

            // Kemana pasien pergi. Nilai dari App\Enums\DischargeDestination.
            $table->string('discharge_destination', 20)->nullable();

            // Catatan/alasan, mis. "kondisi membaik, kontrol 7 hari".
            $table->text('discharge_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table) {
            $table->dropColumn(['discharged_by', 'discharge_destination', 'discharge_note']);
        });
    }
};
