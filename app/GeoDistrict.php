<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class GeoDistrict extends Model
{
    public function division()
    {
        return $this->belongsTo('App\GeoDivision','geo_division_id');
    }

    public function upazilas()
    {
        return $this->hasMany('App\GeoUpazila');
    }
}