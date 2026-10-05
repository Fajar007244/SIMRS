<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assessment awal medis (ASMED), satu per episode.
 *
 * `plan` sengaja longText: farmasi.html menjalankan pencocokan regex pada
 * teks ini untuk menentukan apakah masing-masing kategori obat disebut dalam
 * rencana terapi (lihat config('formularium.plan_checks')).
 */
class Asmed extends Model
{
    use HasFactory;

    protected $fillable = [
        'encounter_id',
        'complaint',
        'history',
        'physical_exam',
        'vitals',
        'plan',
        'examiner',
        'examined_at',
    ];

    protected function casts(): array
    {
        return [
            'vitals' => 'array',
            'examined_at' => 'datetime',
        ];
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * True bila rencana terapi sudah diisi, dipakai sebagai syarat sebelum
     * panel farmasi menampilkan rencana.
     */
    public function hasPlan(): bool
    {
        return trim((string) $this->plan) !== '';
    }
}
