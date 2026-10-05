<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Analisa Gas Darah (AGD).
     *
     * Terpisah dari support_results karena tiap parameter punya satuan,
     * presisi, dan rentang referensi sendiri. Empat parameter pH, pCO2, pO2,
     * dan HCO3 tidak nullable karena saveAbg() di phase1 mewajibkan isi
     * minimal pH dan PaCO2.
     */
    public function up(): void
    {
        Schema::create('abg_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->decimal('ph', 4, 2);
            $table->decimal('pco2', 5, 1);
            $table->decimal('po2', 5, 1);
            $table->decimal('hco3', 5, 1);
            $table->decimal('be', 5, 1)->nullable();
            $table->decimal('sao2', 5, 1)->nullable();
            $table->decimal('fio2', 4, 1)->nullable();
            $table->string('method')->nullable();
            $table->timestamp('measured_at');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['encounter_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abg_results');
    }
};
