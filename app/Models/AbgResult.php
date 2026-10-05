<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEncounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Analisa Gas Darah (AGD).
 *
 * Terpisah dari SupportResult karena setiap parameter punya satuan, presisi,
 * dan rentang referensi sendiri. Baris ini mengisi tabel `abg_results`
 * yang setara dengan array `support[...].abg` pada phase1.
 */
class AbgResult extends Model
{
    use BelongsToEncounter, HasFactory;

    protected $fillable = [
        'encounter_id',
        'ph',
        'pco2',
        'po2',
        'hco3',
        'be',
        'sao2',
        'fio2',
        'method',
        'measured_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'ph' => 'decimal:2',
            'pco2' => 'decimal:1',
            'po2' => 'decimal:1',
            'hco3' => 'decimal:1',
            'be' => 'decimal:1',
            'sao2' => 'decimal:1',
            'fio2' => 'decimal:1',
            'measured_at' => 'datetime',
        ];
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('measured_at')->orderByDesc('id');
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('measured_at', [$from, $to]);
    }

    /**
     * Ringkasan sekali tampil untuk kartu AGD.
     *
     * @return array<string, string>
     */
    public function summary(): array
    {
        return [
            'pH' => (string) $this->ph,
            'PaCO2' => (string) $this->pco2,
            'PaO2' => (string) $this->po2,
            'HCO3' => (string) $this->hco3,
            'BE' => (string) $this->be,
            'SaO2' => (string) $this->sao2,
            'FiO2' => $this->fio2 === null ? '-' : (string) $this->fio2,
            'method' => $this->method ?? '-',
        ];
    }
}
