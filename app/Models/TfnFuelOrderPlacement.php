<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TfnFuelOrderPlacement extends Model
{
    protected $fillable = [
        'order_number',
        'voucher_number',
        'vehicle_registration',
        'product_code',
        'litres',
        'customer_reference',
        'user_id',
        'placed_by_name',
        'placed_at',
    ];

    protected function casts(): array
    {
        return [
            'litres'     => 'decimal:2',
            'placed_at'  => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
