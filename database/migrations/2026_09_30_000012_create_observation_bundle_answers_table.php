<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jawaban item bundle untuk satu observasi.
     *
     * UNIQUE (observation_id, item_key) mengikuti pola phase1: satu item bundle
     * hanya boleh punya satu jawaban per observasi. `item_key` yang dipakai
     * adalah kunci dari config('hai.bundle_items') seperti 'vap_1'.
     *
     * `answer` menyimpan 'ya' / 'tidak' (App\Enums\BundleAnswer). phase1
     * menyimpan bentuk kapital 'Ya' / 'Tidak'; normalisasi ke lowercase
     * dilakukan di layer aplikasi.
     */
    public function up(): void
    {
        Schema::create('observation_bundle_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();
            $table->string('bundle_group');
            $table->string('item_key');
            $table->string('answer');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['observation_id', 'item_key'], 'observation_bundle_item_unique');
            $table->index(['bundle_group', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_bundle_answers');
    }
};
