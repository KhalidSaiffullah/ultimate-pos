<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class EcourierOrder extends Model
{
    //
    protected $table = 'ecourier_order';
    protected $fillable =
        [
            'transaction_id',
            'order_status',
            'tracking_id',
            'order_json'
        ];
}
