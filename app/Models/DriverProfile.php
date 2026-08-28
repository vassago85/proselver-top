<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
     * Trade plate normalisation.
     *
     * The trade plate is what TFN's POS reads at the pump when the
     * transported vehicle has no permanent plate of its own (which is
     * the common case for new-from-plant units).  TFN requires the
     * VehicleRegistration string on every transaction to be alphanumeric,
     * uppercase, no spaces (e.g. "TEST123GP") -- and it must exactly
     * match what was on the order.  Stripping whitespace + upper-casing
     * on both read and write means:
     *
     *   - the admin can type "tp jhb 11" or "TP-JHB-11" or "tpjhb11"
     *     and every downstream comparison collapses to "TPJHB11"
     *   - historical rows that predate this normalisation still render
     *     uppercase on the operations page (get side is safe)
     *   - the reconciler that matches Job.registration OR trade_plate
     *     against Transaction.VehicleRegistration doesn't need to know
     *     about the raw form the driver was captured with
     *
     * Mirrors Job.registration() (see Job.php ~line 621).
     */
    protected function tradePlate(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => $v === null ? null : self::normalisePlate($v),
            set: fn ($v) => $v === null ? null : self::normalisePlate($v),
        );
    }

    /**
     * Strip everything that isn't A-Z / 0-9 and upper-case.  A blank
     * result means "no plate" -- return null so `blank($profile->trade_plate)`
     * still reads as "unset" for consumers.
     *
     * Kept as a public helper so the reconciliation service can use
     * the same rule on incoming TFN VehicleRegistration strings without
     * having to instantiate a model.
     */
    public static function normalisePlate(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $stripped = preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '';
        if ($stripped === '') {
            return null;
        }
        return strtoupper($stripped);
    }

    /**
     * Base location is stored as a plain string (see the
     * DriverBaseLocation reference table) but historical rows are still
     * a stew of casings and stray whitespace. Trim + title-case on read
     * so every surface -- edit form, card view, table view, ops
     * dashboard filter -- shows the same string even before an admin
     * runs a cleanup pass.
     *
     * We do NOT force-canonicalise on write here; the migration handled
     * the historical data, the picker constrains new writes, and
     * leaving raw writes alone means an admin who deliberately types a
     * weird value (say, a new depot name that isn't in the picker
     * yet) sees exactly what they typed.
     */
    protected function baseLocation(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null) {
                    return null;
                }
                $trimmed = preg_replace('/\s+/', ' ', trim((string) $value));
                if ($trimmed === '') {
                    return null;
                }
                // Title-case only if the value looks all-caps or all-
                // lowercase; a mixed-case value the user typed
                // deliberately (e.g. "Cape Town CBD") is preserved.
                if ($trimmed === Str::upper($trimmed) || $trimmed === Str::lower($trimmed)) {
                    return Str::title($trimmed);
                }
                return $trimmed;
            },
        );
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
     * Classify a credential expiry date against a "how soon is soon"
     * window. Returns [pillVariant, pillLabel, isAtRisk].
     *
     *   - null             -> ['slate', 'Missing',              false]
     *   - past             -> ['red',   'Expired · d M Y',      true]
     *   - within $soonDays -> ['amber', 'Due d M Y',            true]
     *   - later than that  -> ['green', 'd M Y',                false]
     *
     * Lives on the model rather than inside the operations blade
     * because two audit fixes depend on the third element:
     *
     *   - the Compliance Risks table on Driver Operations only
     *     colours the at-risk column so a row that appears because
     *     ONE credential is near expiry isn't drowned by two green
     *     pills for the other two;
     *   - unit tests can exercise the classification without having
     *     to render the ops dashboard (which uses Postgres-only
     *     aggregate SQL and cannot run under SQLite).
     */
    public static function expiryBadge(?\Illuminate\Support\Carbon $date, int $soonDays): array
    {
        if ($date === null) {
            return ['slate', 'Missing', false];
        }
        if ($date->isPast()) {
            return ['red', 'Expired · ' . $date->format('d M Y'), true];
        }
        // Carbon 3's diffInDays is signed by default, so for future
        // dates it returns a NEGATIVE float and "<= $soonDays" was
        // silently true for every date beyond today. That's what the
        // audit observed: a licence dated 2030 rendered as amber
        // "Due 15 Dec 2030" alongside a genuinely-near trade plate,
        // diluting the risk signal. Compute the horizon as an actual
        // date so the comparison is unambiguous and version-safe.
        $horizon = now()->startOfDay()->addDays($soonDays)->endOfDay();
        if ($date->lessThanOrEqualTo($horizon)) {
            return ['amber', 'Due ' . $date->format('d M Y'), true];
        }
        return ['green', $date->format('d M Y'), false];
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
