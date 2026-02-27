<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobEvent extends Model
{
    protected $fillable = [
        'job_id',
        'user_id',
        'event_type',
        'event_at',
        'latitude',
        'longitude',
        'notes',
        'synced_at',
        'client_uuid',
    ];

    const TYPE_ARRIVED_PICKUP = 'arrived_pickup';
    const TYPE_VEHICLE_READY = 'vehicle_ready_confirmed';
    const TYPE_DEPARTED_PICKUP = 'departed_pickup';
    const TYPE_ARRIVED_DELIVERY = 'arrived_delivery';
    const TYPE_DOCUMENTS_UPLOADED = 'documents_uploaded';
    const TYPE_POD_SCANNED = 'pod_scanned';
    const TYPE_JOB_COMPLETED = 'job_completed';

    const TYPES = [
        self::TYPE_ARRIVED_PICKUP,
        self::TYPE_VEHICLE_READY,
        self::TYPE_DEPARTED_PICKUP,
        self::TYPE_ARRIVED_DELIVERY,
        self::TYPE_DOCUMENTS_UPLOADED,
        self::TYPE_POD_SCANNED,
        self::TYPE_JOB_COMPLETED,
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'synced_at' => 'datetime',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
