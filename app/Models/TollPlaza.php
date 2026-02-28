<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TollPlaza extends Model
{
    protected $fillable = [
        'road_name', 'plaza_name', 'plaza_type', 'telephone',
        'latitude', 'longitude',
        'class_1_fee', 'class_2_fee', 'class_3_fee', 'class_4_fee',
        'effective_from', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'class_1_fee' => 'decimal:2',
            'class_2_fee' => 'decimal:2',
            'class_3_fee' => 'decimal:2',
            'class_4_fee' => 'decimal:2',
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function feeForClass(int $tollClass): float
    {
        return match ($tollClass) {
            1 => (float) $this->class_1_fee,
            2 => (float) $this->class_2_fee,
            3 => (float) $this->class_3_fee,
            4 => (float) $this->class_4_fee,
            default => 0.0,
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
