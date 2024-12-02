<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class GeoUpazila extends Model
{
    public function district()
    {
        return $this->belongsTo('App\GeoDistrict','geo_district_id');
    }

    public function unions()
    {
        return $this->hasMany('App\GeoUnion');
    }
}