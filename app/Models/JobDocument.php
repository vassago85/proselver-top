<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobDocument extends Model
{
    protected $fillable = [
        'job_id',
        'uploaded_by_user_id',
        'category',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'file_hash',
    ];

    const CATEGORY_PO = 'purchase_order';
    const CATEGORY_POD = 'proof_of_delivery';
    const CATEGORY_INVOICE = 'invoice';
    const CATEGORY_FUEL_SLIP = 'fuel_slip';
    const CATEGORY_PHOTO = 'photo';
    const CATEGORY_OTHER = 'other';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
