<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Courier extends Model
{
    protected $guarded = ['id'];

    public function stores()
    {
        return $this->hasMany('App\Store');
    }
}
