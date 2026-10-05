<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom identitas petugas pada tabel users.
     *
     * Sengaja tanpa modifier `after()`: modifier itu hanya berguna di MySQL dan
     * diabaikan oleh grammar PostgreSQL maupun SQLite, jadi tidak perlu.
     *
     * `role` sengaja berupa string biasa, bukan kolom enum native, supaya
     * pgsql dan sqlite berperilaku identik. Nilai yang valid ditegakkan di
     * layer aplikasi lewat App\Enums\UserRole.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique();
            $table->string('role')->default('perawat');
            $table->string('specialty')->nullable();
            $table->string('phone')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'role', 'specialty', 'phone']);
        });
    }
};
