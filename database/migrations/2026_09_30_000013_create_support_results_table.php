<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hasil penunjang generik (laboratorium, darah, mikroba, radiologi).
     *
     * AGD TIDAK ada di sini karena bentuk datanya blood-gas bertipe kolom
     * (pH, pCO2, pO2, ...) dan punya tabel sendiri: abg_results.
     *
     * `value` menyimpan string tampilan apa adanya dari hasil ("Pending",
     * "Escherichia coli > 10^5 CFU/mL"), sedangkan `numeric_value` menyimpan
     * padanan angkanya untuk grafik tren. Keduanya diisi sesuai jenis data:
     * hasil kultur mengisi `value`, hasil angka mengisi keduanya.
     */
    public function up(): void
    {
        Schema::create('support_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->string('group');
            $table->string('result_key');
            $table->text('value');
            $table->decimal('numeric_value', 12, 4)->nullable();
            $table->string('unit')->nullable();
            $table->string('flag')->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('resulted_at');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['encounter_id', 'group', 'result_key']);
            $table->index(['encounter_id', 'resulted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_results');
    }
};
