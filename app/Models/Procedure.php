<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEncounter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Prosedur / tindakan episode (ICD-9-CM).
 */
class Procedure extends Model
{
    use BelongsToEncounter, HasFactory;

    protected $fillable = [
        'encounter_id',
        'name',
        'code',
        'performed_at',
        'operator',
    ];

    protected function casts(): array
    {
        return [
            'performed_at' => 'date',
        ];
    }
}
