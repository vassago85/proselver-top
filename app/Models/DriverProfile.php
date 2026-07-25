<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverProfile extends Model
{
    public const ID_TYPE_SA_ID = 'sa_id';
    public const ID_TYPE_PASSPORT = 'passport';
    public const ID_TYPE_OTHER = 'other';

    // Off-roster lifecycle reasons. Keep in sync with the service and
    // the edit-page UI. The labels are the single source of truth for
    // how these show up on screen / in audit logs.
    public const REASON_RETIRED   = 'retired';
    public const REASON_RESIGNED  = 'resigned';
    public const REASON_DISMISSED = 'dismissed';
    public const REASON_DECEASED  = 'deceased';
    public const REASON_OTHER     = 'other';

    public const REASON_LABELS = [
        self::REASON_RETIRED   => 'Retired',
        self::REASON_RESIGNED  => 'Resigned',
        self::REASON_DISMISSED => 'Dismissed',
        self::REASON_DECEASED  => 'Deceased',
        self::REASON_OTHER     => 'Other',
    ];

    protected $fillable = [
        'user_id',
        'id_number',
        'id_type',
        'cellphone',
        'base_location',
        'rate_per_movement_cents',
        'trade_plate',
        'trade_plate_expiry',
        'trade_plate_returned_at',
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
        'off_roster_at',
        'off_roster_reason',
        'off_roster_notes',
        'off_roster_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'license_expiry' => 'date',
            'prdp_expiry' => 'date',
            'trade_plate_expiry' => 'date',
            'trade_plate_returned_at' => 'datetime',
            'off_roster_at' => 'datetime',
            'rate_per_movement_cents' => 'integer',
        ];
    }

    /**
     * Rate per completed movement, in rand.  Null when unset.
     * Used by the month-end pay report and the driver edit form.
     */
    public function ratePerMovementRand(): ?float
    {
        return $this->rate_per_movement_cents === null
            ? null
            : (float) $this->rate_per_movement_cents / 100;
    }

    public function offRosterBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'off_roster_by_user_id');
    }

    public function isOffRoster(): bool
    {
        return $this->off_roster_at !== null;
    }

    public function reasonLabel(): ?string
    {
        if (!$this->off_roster_reason) { return null; }
        return self::REASON_LABELS[$this->off_roster_reason] ?? ucfirst($this->off_roster_reason);
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
