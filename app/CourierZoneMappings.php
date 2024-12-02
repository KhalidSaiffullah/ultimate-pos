<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class CourierZoneMappings extends Model
{
    protected $table = 'courier_zone_mappings';
    protected $fillable =
        [
            'division_name',
            'city_name',
            'area_name',
            'zone_name'
        ];
    //
}
