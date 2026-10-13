<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak audit akses rekam medis (UU PDP Pasal 26).
 *
 * KENAPA TABEL INI ADA
 *
 * docs/AUTHORIZATION.md menetapkan semua peran boleh membaca seluruh data
 * ("yang dibatasi adalah menulis, bukan membaca"). Itu keputusan klinis yang
 * sah - tetapi tanpa pencatatan, keputusan itu tidak bisa diaudit: kebocoran
 * tidak bisa dibuktikan dan akun yang dibobol tidak bisa direkonstruksi.
 * Tabel ini mencatat SIAPA membuka data PASIEN mana, kapan, lewat modul apa.
 *
 * YANG SENGAJA BERBEDA DARI RANCANGAN AWAL
 *
 *  1. TIDAK ADA foreign key. Log audit harus selamat dari penghapusan data
 *     yang dirujuknya: bila encounter/pasien/user dihapus, baris log tetap
 *     ada (itulah gunanya audit). FK cascade akan menghapus bukti; FK
 *     restrict akan menghalangi penghapusan yang sah. Kolomnya tetap
 *     di-index untuk kueri investigasi.
 *
 *  2. `patient_id` adalah BIGINT (kunci primer patients.id), BUKAN string
 *     kode pasien. encounters.patient_id adalah FK integer ke patients.id;
 *     menyimpan kode string di sini akan salah tipe dan salah nilai.
 *
 *  3. Append-only: tidak ada updated_at, tidak ada endpoint hapus. Retensi
 *     (patokan umum 3 tahun untuk audit kesehatan) diputuskan tim RS;
 *     penghapusan hanya lewat job terjadwal, bukan endpoint.
 *
 *  4. Hanya metadata akses, tidak pernah isi rekam medis. user_agent
 *     dipotong 255 karakter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('encounter_id', 64)->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('action', 20);
            $table->string('module', 50);
            $table->string('route', 255);
            $table->string('method', 10)->default('GET');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['encounter_id', 'created_at']);
            $table->index(['patient_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
