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
        'client_uuid',
        'captured_at',
        'latitude',
        'longitude',
        'notes',
    ];

    const CATEGORY_PO = 'purchase_order';
    const CATEGORY_POD = 'proof_of_delivery';
    const CATEGORY_COLLECTION_NOTE = 'collection_note';
    const CATEGORY_INVOICE = 'invoice';
    const CATEGORY_FUEL_SLIP = 'fuel_slip';
    const CATEGORY_FOOD_SLIP = 'food_slip';
    const CATEGORY_TOLL_SLIP = 'toll_slip';
    const CATEGORY_PARKING_SLIP = 'parking_slip';
    const CATEGORY_DAMAGE_PHOTO = 'damage_photo';
    const CATEGORY_PHOTO = 'photo';
    const CATEGORY_OTHER = 'other';

    /**
     * Single source of truth for document categories that the driver PWA
     * (or any future client) is allowed to upload.
     */
    public static function allowedCategories(): array
    {
        return [
            self::CATEGORY_POD,
            self::CATEGORY_COLLECTION_NOTE,
            self::CATEGORY_FUEL_SLIP,
            self::CATEGORY_FOOD_SLIP,
            self::CATEGORY_TOLL_SLIP,
            self::CATEGORY_PARKING_SLIP,
            self::CATEGORY_DAMAGE_PHOTO,
            self::CATEGORY_PHOTO,
            self::CATEGORY_OTHER,
        ];
    }

    /**
     * Petty cash / expense categories (photo + category, no amount in Phase 1).
     */
    public static function pettyCashCategories(): array
    {
        return [
            self::CATEGORY_FUEL_SLIP,
            self::CATEGORY_FOOD_SLIP,
            self::CATEGORY_TOLL_SLIP,
            self::CATEGORY_PARKING_SLIP,
            self::CATEGORY_OTHER,
        ];
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'captured_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
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
