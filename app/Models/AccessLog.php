<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu akses terautentikasi ke satu modul klinis.
 *
 * Ditulis oleh App\Http\Middleware\LogRecordAccess. Append-only: tidak ada
 * updated_at dan tidak ada jalur hapus lewat aplikasi. Relasi di bawah
 * memakai kunci yang SAMA dengan kolomnya (id integer), bukan kode string -
 * lihat catatan di migration create_access_logs_table.
 */
class AccessLog extends Model
{
    public const ACTION_READ = 'read';

    public const ACTION_WRITE = 'write';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'encounter_id',
        'patient_id',
        'action',
        'module',
        'route',
        'method',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function encounter(): BelongsTo
    {
        // encounters.encounter_id adalah kolom string unik, bukan PK.
        return $this->belongsTo(Encounter::class, 'encounter_id', 'encounter_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
