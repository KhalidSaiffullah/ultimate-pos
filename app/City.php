<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    public function zones()
    {
        return $this->hasMany('App\Zone');
    }
}
