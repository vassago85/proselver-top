<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Hub extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'address',
        'city',
        'province',
        'latitude',
        'longitude',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Hub $hub) {
            if (empty($hub->uuid)) {
                $hub->uuid = (string) Str::uuid();
            }
        });
    }
}
