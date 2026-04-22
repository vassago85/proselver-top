<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverProfile extends Model
{
    public const ID_TYPE_SA_ID = 'sa_id';
    public const ID_TYPE_PASSPORT = 'passport';
    public const ID_TYPE_OTHER = 'other';

    protected $fillable = [
        'user_id',
        'id_number',
        'id_type',
        'cellphone',
        'base_location',
        'trade_plate',
        'trade_plate_expiry',
        'tracker_id',
        'camera_id',
        'toll_card_number',
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
            'trade_plate_expiry' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Human label for the primary identity document ("ID Number" / "Passport").
     * Used on the collection note and driver card views so the label always
     * matches the data.
     */
    public function idDocumentLabel(): string
    {
        return match ($this->id_type) {
            self::ID_TYPE_PASSPORT => 'Passport',
            self::ID_TYPE_OTHER    => 'Doc No.',
            default                => 'ID Number',
        };
    }
}
