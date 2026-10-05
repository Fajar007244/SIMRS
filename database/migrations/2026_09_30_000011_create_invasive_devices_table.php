<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perangkat invasif yang terpasang pada satu episode.
     *
     * Di phase1 perangkat bersifat per-observasi (tiap jam ditandai hadir atau
     * tidak). Di sini perangkat adalah baris yang berdiri sendiri dengan
     * rentang tanggal, jadi "lama pakai" bisa dihitung tanpa menggabungkan
     * seluruh observasi. Status saat ini dibaca dari flag `is_active`.
     *
     * `device_code` memakai nilai App\Enums\DeviceCode.
     */
    public function up(): void
    {
        Schema::create('invasive_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->string('device_code');
            $table->string('label')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['encounter_id', 'is_active']);
            $table->index(['encounter_id', 'device_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invasive_devices');
    }
};
