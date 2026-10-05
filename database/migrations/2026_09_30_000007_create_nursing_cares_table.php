<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asuhan keperawatan / kebidanan.
     *
     * TIDAK unik pada encounter_id: asuhan bisa diinput berulang setiap shift.
     * Karena itu `Encounter::completion.nursingCare` memakai relasi
     * `latestNursingCare()` yang mengambil baris terakhir menurut recorded_at.
     *
     * Kolom `is_latest` disimpan supaya pencarian "asuhan terakhir" tidak
     * selalu bergantung pada agregasi MAX(recorded_at) yang mahal.
     */
    public function up(): void
    {
        Schema::create('nursing_cares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->text('assessment')->nullable();
            $table->text('problems')->nullable();
            $table->text('interventions')->nullable();
            $table->string('nurse');
            $table->string('shift')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->boolean('is_latest')->default(false);
            $table->timestamps();

            $table->index(['encounter_id', 'recorded_at']);
            $table->index(['encounter_id', 'is_latest']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nursing_cares');
    }
};
