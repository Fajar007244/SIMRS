<?php

namespace App\Models\Concerns;

use App\Models\Encounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relasi dan scope standar untuk seluruh tabel yang menempel pada satu
 * episode perawatan.
 *
 * Dipakai oleh Diagnoses, Procedures, Asmeds, NursingCare, MedicalNote,
 * Observation, InvasiveDevice, SupportResult, dan AbgResult. Tabel anak
 * yang induknya adalah Observation (ObservationMedication dan
 * ObservationBundleAnswer) memakai relasi ke observation, bukan ke encounter.
 *
 * TIDAK memakai soft delete di mana pun: phase1 tidak memakai soft delete, dan
 * dokumen klinis tidak boleh hilang hanya karena dihapus.
 */
trait BelongsToEncounter
{
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /**
     * Batasi kueri ke satu episode.
     */
    public function scopeForEncounter(Builder $query, int|Encounter|string $encounter): Builder
    {
        if ($encounter instanceof Encounter) {
            return $query->where($this->qualifyColumn('encounter_id'), $encounter->getKey());
        }

        if (is_int($encounter) || ctype_digit((string) $encounter)) {
            return $query->where($this->qualifyColumn('encounter_id'), (int) $encounter);
        }

        return $query->whereHas('encounter', function (Builder $q) use ($encounter) {
            $q->where('encounter_id', $encounter);
        });
    }

    /**
     * Urut berdasarkan waktu paling baru lebih dulu.
     */
    public function scopeLatestFirst(Builder $query, string $column = 'created_at'): Builder
    {
        return $query->orderByDesc($this->qualifyColumn($column));
    }
}
