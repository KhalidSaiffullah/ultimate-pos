<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class GeoDivision extends Model
{
    public function districts()
    {
        return $this->hasMany('App\GeoDistrict');
    }
}