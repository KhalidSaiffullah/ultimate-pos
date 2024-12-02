<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{


    public function contact()
    {
        return $this->belongsTo('App\Contact')->where('deleted_at', null);
    }

    public function transaction()
    {
        return $this->hasMany('App\Transaction');
    }
}
