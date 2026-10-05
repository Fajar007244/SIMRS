<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Diagnosis episode (ICD-10). Satu episode dapat punya banyak diagnosis:
     * satu `utama` dan sembarang `penyerta`.
     *
     * `text` adalah nama diagnosis bebas; `code` adalah kode ICD-10 opsional.
     * Keduanya string biasa, bukan kolom enum native.
     */
    public function up(): void
    {
        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->string('type')->default('utama');
            $table->text('text');
            $table->string('code')->nullable();
            $table->string('author')->nullable();
            $table->timestamps();

            $table->index(['encounter_id', 'type']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnoses');
    }
};
