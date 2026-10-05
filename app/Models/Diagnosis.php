<?php

namespace App\Models;

use App\Enums\DiagnosisType;
use App\Models\Concerns\BelongsToEncounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Diagnosis episode (ICD-10). Satu episode dapat memuat satu diagnosis
 * `utama` dan beberapa diagnosis `penyerta`.
 */
class Diagnosis extends Model
{
    use BelongsToEncounter, HasFactory;

    protected $fillable = [
        'encounter_id',
        'type',
        'text',
        'code',
        'author',
    ];

    protected function casts(): array
    {
        return [
            'type' => DiagnosisType::class,
        ];
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('type', DiagnosisType::UTAMA->value);
    }

    public function scopeComorbid(Builder $query): Builder
    {
        return $query->where('type', DiagnosisType::PENYERTA->value);
    }

    public function isPrimary(): bool
    {
        return $this->type === DiagnosisType::UTAMA;
    }
}
