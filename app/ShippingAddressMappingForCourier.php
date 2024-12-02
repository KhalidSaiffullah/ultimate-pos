<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ShippingAddressMappingForCourier extends Model
{
    protected $table = 'shipping_address_mapping_for_courier';

    protected $fillable = [
        'courier_type',
        'pathao_city_name',
        'pathao_zone_name',
        'pathao_area_name',
        'pathao_city_id',
        'pathao_zone_id',
        'pathao_area_id',
        'transaction_id',
        'shipping_address',
        'created_by',
    ];

    // Define any relationships, accessors, or mutators here if needed
}