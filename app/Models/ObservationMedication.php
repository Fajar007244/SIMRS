<?php

namespace App\Models;

use App\Enums\MedicationCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Koreksi pemberian obat / cairan pada satu observasi.
 *
 * Induknya Observation, bukan Encounter. Namun dapat dijangkau dari
 * encounter lewat relasi hasManyThrough pada model Encounter.
 */
class ObservationMedication extends Model
{
    use HasFactory;

    protected $fillable = [
        'observation_id',
        'name',
        'dose',
        'category',
        'volume',
        'route',
        'status',
        'given_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'category' => MedicationCategory::class,
            'volume' => 'decimal:1',
            'given_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function scopeForCategory(Builder $query, MedicationCategory|array $categories): Builder
    {
        $categories = is_array($categories) ? $categories : [$categories];

        return $query->whereIn('category', array_map(
            fn (MedicationCategory $category) => $category->value,
            $categories
        ));
    }

    public function scopeGiven(Builder $query): Builder
    {
        return $query->where('status', 'diberikan');
    }
}
