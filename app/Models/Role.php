<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'tier', 'description'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withTimestamps();
    }

    public function isInternal(): bool
    {
        return $this->tier === 'internal';
    }

    public function isDealer(): bool
    {
        return $this->tier === 'dealer';
    }

    public function isDriver(): bool
    {
        return $this->slug === 'driver';
    }
}
