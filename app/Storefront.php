<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Storefront extends Model
{
    /**
     * Get the Business.
     */
    public function business()
    {
        return $this->belongsTo(\App\Business::class);
    }
}
