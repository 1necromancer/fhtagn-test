<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PickupPoint extends Model
{
    protected $fillable = [
        'code',
        'title',
    ];

    public function orderDeliveries(): HasMany
    {
        return $this->hasMany(OrderDelivery::class);
    }
}
