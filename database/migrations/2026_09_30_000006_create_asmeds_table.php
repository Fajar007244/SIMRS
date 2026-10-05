<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assessment awal medis (ASMED) per episode.
     *
     * UNIQUE pada encounter_id: hanya boleh ada satu ASMED per episode. Ini
     * yang membuat `Encounter::completion.asmed` cukup dijawab dengan
     * `exists()`, tidak perlu menghitung.
     *
     * `plan` memakai longText karena phase1/farmasi.html menjalankan
     * pencocokan regex pada teks rencana terapi ini (PLAN_CHECKS) untuk
     * menentukan kartu Inotropik / Sedasi / Antibiotik / Cairan.
     *
     * `vitals` nullable json: prototype menyimpannya sebagai string teks
     * bebas ("TD 88/52 mmHg, HR 118x/mnt, ..."), jadi nullable agar tidak
     * memaksa bentuk json pada data lama.
     */
    public function up(): void
    {
        Schema::create('asmeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->unique()->constrained('encounters')->cascadeOnDelete();
            $table->text('complaint')->nullable();
            $table->text('history')->nullable();
            $table->text('physical_exam')->nullable();
            $table->json('vitals')->nullable();
            $table->longText('plan')->nullable();
            $table->string('examiner')->nullable();
            $table->timestamp('examined_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asmeds');
    }
};
