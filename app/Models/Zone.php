<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function originRates(): HasMany
    {
        return $this->hasMany(ZoneRate::class, 'origin_zone_id');
    }

    public function destinationRates(): HasMany
    {
        return $this->hasMany(ZoneRate::class, 'destination_zone_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
