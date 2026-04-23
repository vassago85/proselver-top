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
    // Dashboard cluster shot — fuel gauge + odometer reading in a single
    // frame. Captured at both pickup and delivery so disputes about
    // "how much fuel was in it" / "it's done 1000km more than we agreed"
    // have photographic evidence. Pairs with the slot tag to distinguish
    // pickup_dashboard vs delivery_dashboard.
    const CATEGORY_DASHBOARD = 'dashboard';
    // Manufacturer data plate — the VIN/compliance plate on the B-pillar
    // or engine bay. Captured once at pickup only; confirms we collected
    // the correct vehicle before it leaves the yard.
    const CATEGORY_DATA_PLATE = 'data_plate';
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
            self::CATEGORY_DASHBOARD,
            self::CATEGORY_DATA_PLATE,
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

    /**
     * Map of slot tags → UI-friendly labels. The slot tag is produced by
     * the driver PWA and stuffed into the notes field as `slot:pickup_front`
     * (see driverCapture queue) so we don't need a schema migration to
     * carry position info. If the tag isn't in this map we fall back to
     * the category label.
     *
     * Keys intentionally mirror the tile slots declared in
     * resources/views/pages/driver/job.blade.php.
     */
    public const POSITION_LABELS = [
        'pickup_front'      => 'Front',
        'pickup_rear'       => 'Rear',
        'pickup_left'       => 'Left side',
        'pickup_right'      => 'Right side',
        'pickup_dashboard'  => 'Dashboard (pickup)',
        'pickup_data_plate' => 'Data plate',
        'delivery_front'    => 'Delivery (1)',
        'delivery_other'    => 'Delivery (2)',
        'delivery_dashboard'=> 'Dashboard (delivery)',
        'collection_note'   => 'Collection note',
        'proof_of_delivery' => 'POD',
    ];

    /**
     * Extracts the `slot:xxx` prefix from the notes field. Returns null
     * when no tag was attached (older uploads, damage photos with a
     * freeform note, etc). Safe on null notes.
     */
    public function slotTag(): ?string
    {
        $notes = $this->notes ?? '';
        if (!is_string($notes) || !str_starts_with($notes, 'slot:')) {
            return null;
        }
        $tag = substr($notes, 5);
        return $tag !== '' ? $tag : null;
    }

    /**
     * Display-ready label for this document — the position tag if we have
     * one, otherwise the humanised category. This is what the UI badges
     * render in both /admin/documents and the per-job photo grid.
     */
    public function positionLabel(): string
    {
        $slot = $this->slotTag();
        if ($slot && isset(self::POSITION_LABELS[$slot])) {
            return self::POSITION_LABELS[$slot];
        }

        return match ($this->category) {
            self::CATEGORY_DASHBOARD    => 'Dashboard',
            self::CATEGORY_DATA_PLATE   => 'Data plate',
            self::CATEGORY_POD          => 'POD',
            self::CATEGORY_COLLECTION_NOTE => 'Collection note',
            self::CATEGORY_DAMAGE_PHOTO => 'Damage',
            self::CATEGORY_FUEL_SLIP    => 'Fuel slip',
            self::CATEGORY_FOOD_SLIP    => 'Food slip',
            self::CATEGORY_TOLL_SLIP    => 'Toll slip',
            self::CATEGORY_PARKING_SLIP => 'Parking slip',
            self::CATEGORY_PO           => 'Purchase order',
            self::CATEGORY_PHOTO        => 'Photo',
            default                     => ucfirst(str_replace('_', ' ', (string) $this->category)),
        };
    }

    /**
     * Tailwind colour class for the position badge. Keeps pickup, delivery,
     * paperwork and damage visually distinct when you scan the grouped
     * documents page. Unknown tags fall through to neutral slate.
     */
    public function positionBadgeClasses(): string
    {
        $slot = $this->slotTag();

        if ($slot && str_starts_with($slot, 'pickup_')) {
            return 'bg-blue-50 text-blue-700 border-blue-200';
        }
        if ($slot && str_starts_with($slot, 'delivery_')) {
            return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        }

        return match ($this->category) {
            self::CATEGORY_DAMAGE_PHOTO => 'bg-rose-50 text-rose-700 border-rose-200',
            self::CATEGORY_POD,
            self::CATEGORY_COLLECTION_NOTE => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::CATEGORY_DASHBOARD,
            self::CATEGORY_DATA_PLATE => 'bg-blue-50 text-blue-700 border-blue-200',
            self::CATEGORY_FUEL_SLIP,
            self::CATEGORY_FOOD_SLIP,
            self::CATEGORY_TOLL_SLIP,
            self::CATEGORY_PARKING_SLIP,
            self::CATEGORY_OTHER => 'bg-amber-50 text-amber-700 border-amber-200',
            default => 'bg-slate-50 text-slate-700 border-slate-200',
        };
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
