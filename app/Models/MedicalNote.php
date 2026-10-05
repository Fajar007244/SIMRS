<?php

namespace App\Models;

use App\Enums\NursingShift;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToEncounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catatan progres CPPT (Chart Progress Doctor's Note).
 *
 * Penulis disimpan sebagai snapshot (nama, peran, spesialisasi) dan bukan
 * foreign key ke users, karena dokumen CPPT harus tetap terbaca apa adanya
 * meski akun petugas dinonaktifkan atau diubah.
 *
 * `shift` nullable: lihat migration 2026_10_02_000001. Kolom ini hanya diisi
 * dari form "Tambah CPPT"; baris lama dan baris yang penginya tidak memilih
 * shift tetap null.
 */
class MedicalNote extends Model
{
    use BelongsToEncounter, HasFactory;

    protected $fillable = [
        'encounter_id',
        'author_name',
        'author_role',
        'author_specialty',
        'note_type',
        'shift',
        'subjective',
        'objective',
        'assessment',
        'plan',
        'noted_at',
        'order_number',
    ];

    protected function casts(): array
    {
        return [
            'author_role' => UserRole::class,
            'shift' => NursingShift::class,
            'noted_at' => 'datetime',
            'order_number' => 'integer',
        ];
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('noted_at')->orderByDesc('order_number');
    }

    public function scopeForRole(Builder $query, ?UserRole $role): Builder
    {
        return $role === null ? $query : $query->where('author_role', $role->value);
    }
}
