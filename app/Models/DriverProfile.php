<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverProfile extends Model
{
    protected $fillable = [
        'user_id',
        'id_number',
        'cellphone',
        'base_location',
        'license_code',
        'license_number',
        'license_expiry',
        'prdp_expiry',
        'license_document_disk',
        'license_document_path',
        'license_document_filename',
        'pdp_document_disk',
        'pdp_document_path',
        'pdp_document_filename',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'license_expiry' => 'date',
            'prdp_expiry' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
