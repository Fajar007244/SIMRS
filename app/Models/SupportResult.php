<?php

namespace App\Models;

use App\Enums\SupportGroup;
use App\Models\Concerns\BelongsToEncounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Hasil penunjang generik: laboratorium, darah, mikroba, dan radiologi.
 *
 * AGD tidak ada di sini karena bentuk datanya berbeda; lihat AbgResult.
 */
class SupportResult extends Model
{
    use BelongsToEncounter, HasFactory;

    protected $fillable = [
        'encounter_id',
        'group',
        'result_key',
        'value',
        'numeric_value',
        'unit',
        'flag',
        'reference',
        'resulted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'group' => SupportGroup::class,
            'numeric_value' => 'decimal:4',
            'resulted_at' => 'datetime',
        ];
    }

    public function scopeForGroup(Builder $query, SupportGroup|array $groups): Builder
    {
        $groups = is_array($groups) ? $groups : [$groups];

        return $query->whereIn('group', array_map(
            fn (SupportGroup $group) => $group->value,
            $groups
        ));
    }

    public function scopeForKey(Builder $query, string|array $keys): Builder
    {
        return $query->whereIn('result_key', (array) $keys);
    }

    /**
     * Hanya baris yang punya padanan angka, yaitu yang bisa diplot pada grafik
     * tren. Hasil kultur mengisi `value` saja sehingga tidak ikut diplot.
     */
    public function scopeNumeric(Builder $query): Builder
    {
        return $query->whereNotNull('numeric_value');
    }

    public function scopeAbnormal(Builder $query): Builder
    {
        return $query->whereIn('flag', ['high', 'low', 'critical', 'abnormal']);
    }
}
