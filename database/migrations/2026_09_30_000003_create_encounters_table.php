<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Episode perawatan / admisi.
     *
     * `diagnosis_summary`, `allergy_alert`, dan `latest_observation_at` adalah
     * denormalisasi yang sengaja disimpan di sini: banner identitas pasien di
     * setiap halaman membacanya, dan denenormalisasi itu menghemat satu join
     * ke tabel anak pada setiap render halaman.
     *
     * Catatan reserved word: kolom `map` di tabel observations adalah kata
     * "reserved-ish" di beberapa engine. PostgreSQL mengetuai kata MAP sebagai
     * NON-reserved, dan Laravel selalu mengapit semua identifier dengan tanda
     * kutip dua pada grammar PostgreSQL, sehingga tetap aman tanpa escaping
     * tambahan. Lihat catatan lengkap di create_observations_table.
     */
    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $table) {
            $table->id();
            $table->string('encounter_id')->unique();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('unit');
            $table->string('bed');
            $table->timestamp('admitted_at');
            $table->timestamp('discharged_at')->nullable();
            $table->string('attending_physician')->nullable();
            $table->string('status')->default('aktif');
            $table->text('diagnosis_summary')->nullable();
            $table->text('allergy_alert')->nullable();
            $table->timestamp('latest_observation_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
            $table->index('unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounters');
    }
};
