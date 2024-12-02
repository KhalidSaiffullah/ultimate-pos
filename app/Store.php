<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    public function Courier()
    {
        return $this->belongsTo('App\Courier');
    }
}
