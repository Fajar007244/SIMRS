<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Koreksi pemberian obat / cairan yang tercatat pada satu observasi.
     *
     * Menggantikan array `medications` pada prototype: di phase1 tiap observasi
     * menyimpan seluruh daftar obat inline, sedangkan di sini setiap baris
     * menjadi satu record agar bisa direkap per tanggal, jam, dan kategori.
     *
     * `category` memakai nilai App\Enums\MedicationCategory; `sort_order`
     * menyimpan urutan saat petugas mengetik di formulir.
     */
    public function up(): void
    {
        Schema::create('observation_medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();
            $table->string('name');
            $table->string('dose')->nullable();
            $table->string('category')->default('Lainnya');
            $table->decimal('volume', 8, 1)->nullable();
            $table->string('route')->nullable();
            $table->string('status')->default('diberikan');
            $table->timestamp('given_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('observation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_medications');
    }
};
