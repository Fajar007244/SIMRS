<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master data pasien.
     *
     * `sex` dan `payment` sengaja disimpan sebagai string, bukan enum native:
     * kolom enum nativepgsql tidak kompatibel dengan sqlite sehingga kedua
     * driver akan berperilaku berbeda. Validasi dilakukan lewat cast enum PHP.
     *
     * Defaults ditulis sebagai literal (bukan UserEnum::case) supaya migration
     * tidak ikut berubah bila enum aplikasi direname di kemudian hari.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('patient_id')->unique();
            $table->string('mrn')->unique();
            $table->string('name');
            $table->string('sex')->default('Laki-Laki');
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('blood_type')->nullable();
            $table->text('allergies')->nullable();
            $table->string('payment')->default('Umum');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
