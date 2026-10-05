<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan progres CPPT (Chart Progress Doctor's Note).
     *
     * Riwayat penulisan memakai urutan pemakaian: satu episode bisa punya
     * banyak CPPT, jadi `order_number` (NOT NULL) dipakai untuk pengurutan
     * stabil di samping waktu penulisan. Default 0 menjaga agar baris yang
     * dibuat tanpa nilai eksplisit tetap punya nilai.
     *
     * `author_role` disimpan sebagai string; nilai sah divalidasi lewat
     * App\Enums\UserRole.
     */
    public function up(): void
    {
        Schema::create('medical_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->string('author_name');
            $table->string('author_role')->nullable();
            $table->string('author_specialty')->nullable();
            $table->string('note_type')->nullable();
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->timestamp('noted_at');
            $table->integer('order_number')->default(0);
            $table->timestamps();

            $table->index(['encounter_id', 'noted_at']);
            $table->index(['encounter_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_notes');
    }
};
