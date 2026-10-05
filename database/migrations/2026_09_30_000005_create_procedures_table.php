<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prosedur / tindakan episode (ICD-9-CM).
     */
    public function up(): void
    {
        Schema::create('procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->date('performed_at');
            $table->string('operator')->nullable();
            $table->timestamps();

            $table->index(['encounter_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedures');
    }
};
