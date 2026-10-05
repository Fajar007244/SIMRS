<?php

namespace App\Models;

use App\Enums\NursingShift;
use App\Models\Concerns\BelongsToEncounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Asuhan keperawatan / kebidanan.
 *
 * Tidak unik per encounter: asuhan diinput ulang tiap shift. Baris terbaru
 * ditandai lewat `is_latest` supaya badge completion tidak selalu perlu
 * agregasi MAX(recorded_at).
 */
class NursingCare extends Model
{
    use BelongsToEncounter, HasFactory;

    protected $fillable = [
        'encounter_id',
        'assessment',
        'problems',
        'interventions',
        'nurse',
        'shift',
        'recorded_at',
        'is_latest',
    ];

    protected function casts(): array
    {
        return [
            'shift' => NursingShift::class,
            'recorded_at' => 'datetime',
            'is_latest' => 'boolean',
        ];
    }

    public function scopeLatest(Builder $query): Builder
    {
        return $query->where('is_latest', true);
    }

    public function scopeForShift(Builder $query, ?NursingShift $shift): Builder
    {
        return $shift === null ? $query : $query->where('shift', $shift->value);
    }
}
