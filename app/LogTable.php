<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class LogTable extends Model
{
    protected $table = 'log_table';
    protected $fillable =
        [
            'error_type',
            'error_details',
            'business_id',
            'store_id'
        ];
    //
}
