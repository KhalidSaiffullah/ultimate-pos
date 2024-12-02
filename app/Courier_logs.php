<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use App\Store;

class Courier_logs extends Model
{
    protected $table = 'courier_logs';

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id', 'store_no');
    }
}
