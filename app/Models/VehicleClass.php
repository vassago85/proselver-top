<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleClass extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'is_active', 'toll_class'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'toll_class' => 'integer',
        ];
    }
}
