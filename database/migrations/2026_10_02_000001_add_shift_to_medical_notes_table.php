<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom `shift` pada `medical_notes`.
     *
     * Catatan CPPT di phase1/cppt.html tidak punya shift, jadi kolom ini
     * ditambahkan untuk form "Tambah CPPT" pada halaman CPPT (tab Timeline):
     * petugas yang menulis catatan progres sering butuh mencatat shift-nya
     * supaya rekap antar shift masih bisa dibaca.
     *
     * SENGaja NULLABLE, bukan NOT NULL dengan nilai bawaan:
     *  - 12 baris medical_notes hasil seed sudah ada dan tidak punya shift.
     *    Kolom NOT NULL tanpa default akan membuat migration itu gagal.
     *  - Null berarti "tidak diisi", bukan "shift tidak diketahui" - itu dua
     *    informasi berbeda dan tidak boleh dipalsukan menjadi Pagi.
     *
     * Nilai sah adalah kasus App\Enums\NursingShift (Pagi / Siang / Sore /
     * Malam). Kolom disimpan sebagai string biasa lalu di-cast enum oleh
     * model MedicalNote, sama seperti nursing_cares.shift.
     *
     * Tidak ada kolom lama yang di-ALTER atau di-DROP.
     */
    public function up(): void
    {
        Schema::table('medical_notes', function (Blueprint $table) {
            $table->string('shift')->nullable()->after('note_type');
        });
    }

    public function down(): void
    {
        Schema::table('medical_notes', function (Blueprint $table) {
            $table->dropColumn('shift');
        });
    }
};
