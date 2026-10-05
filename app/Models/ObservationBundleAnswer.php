<?php

namespace App\Models;

use App\Enums\BundleAnswer;
use App\Enums\BundleGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jawaban satu item bundle pada satu observasi.
 *
 * Unik pada (observation_id, item_key), mengikuti pola phase1 di mana satu
 * item bundle hanya boleh punya satu jawaban per observasi.
 */
class ObservationBundleAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'observation_id',
        'bundle_group',
        'item_key',
        'answer',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'answer' => BundleAnswer::class,
            'bundle_group' => BundleGroup::class,
        ];
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function scopeForGroup(Builder $query, BundleGroup|array $groups): Builder
    {
        $groups = is_array($groups) ? $groups : [$groups];

        return $query->whereIn('bundle_group', array_map(
            fn (BundleGroup $group) => $group->value,
            $groups
        ));
    }

    public function scopeCompliant(Builder $query): Builder
    {
        return $query->where('answer', BundleAnswer::YA->value);
    }

    /**
     * Label item bundle dari config, mis. "Posisi kepala 30 s/d 45" untuk
     * kunci "vap_2". Null bila kuncinya tidak ada di config.
     */
    public function itemLabel(): ?string
    {
        $key = $this->item_key;

        if ($key === null) {
            return null;
        }

        foreach ((array) config('hai.bundle_items', []) as $item) {
            if (($item['key'] ?? null) === $key) {
                return $item['label'] ?? null;
            }
        }

        return null;
    }
}
