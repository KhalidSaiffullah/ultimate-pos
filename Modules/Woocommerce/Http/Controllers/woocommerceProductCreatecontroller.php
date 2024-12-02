<?php

namespace Modules\Woocommerce\Http\Controllers;

use DB;
use Log;
use App\Product;
use App\Business;
use App\Variation;
use App\Transaction;
use App\ProductVariation;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Utils\TransactionUtil;
use App\VariationLocationDetails;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Woocommerce\Utils\WoocommerceUtil;
use App\PurchaseLine;


class woocommerceProductCreatecontroller extends Controller{
    // create products

    private function createProducts($productname, $businessid, $producttype, $product_unit_id, $product_brand_id, $product_category_id, $product_enable_stock, $product_alert_quantity, $productsku, $productslug, $product_barcode_type, $productdescription, $product_createdby, $product_woocommerce_id ){
       
        try{
        $product_create = new Product();
        $product_create -> name = $productname;
        $product_create -> business_id = $businessid;
        $product_create -> type = $producttype;
        $product_create -> unit_id = $product_unit_id;
        $product_create -> brand_id = $product_brand_id;
        $product_create -> category_id = $product_category_id;
        $product_create -> sub_category_id = $productname;
        $product_create -> enable_stock = $product_enable_stock;
        $product_create -> alert_quantity = $product_alert_quantity;
        $product_create -> sku = $productsku;
        $product_create -> slug = $productslug;
        $product_create -> bracode_type = $product_barcode_type;
        $product_create -> product_description = $productdescription;
        $product_create -> created_by = $product_createdby;
        $product_create -> woocommerce_product_id = $product_woocommerce_id;
        $product_create ->save();

        return $product_create;
        }
        catch (\Exception $e) {
            DB::rollBack();
            Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
        }
    }

    private function createProductvariations($product_variation_name, $product_id, $product_variation_is_dummy){

        try{
        $product_variation = new ProductVariation();
        $product_variation -> name = $product_variation_name;
        $product_variation -> product_id = $product_id;
        $product_variation -> is_dummy = $product_variation_is_dummy;
        $product_variation -> save();
        return $product_variation;
        }
        catch (\Exception $e) {
            DB::rollBack();
            Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
        }

    }

    private function createVariations($variation_name, $variation_product_id, $variation_sub_sku, $variation_product_variation_id, $variation_woocommerce_variation_id, $variation_default_purchase_price, $variation_dpp_inc_tax, $variation_profit_percent, $variation_default_sell_price, $variation_sell_price_inc_tax, $variation_deleted_at, $variation_combo){
       
        try{
        $variation = new variation();
        $variation -> name = $variation_name;
        $variation -> product_id = $variation_product_id;
        $variation -> sub_sku = $variation_sub_sku;
        $variation -> product_variation_id = $variation_product_variation_id;
        $variation -> woocommerce_variation_id = $variation_woocommerce_variation_id;
        $variation -> default_purchase_price = $variation_default_purchase_price;
        $variation -> dpp_inc_tax = $variation_dpp_inc_tax;
        $variation -> profit_percent = $variation_profit_percent;
        $variation -> default_sell_price = $variation_default_sell_price;
        $variation -> sell_price_inc_tax = $variation_sell_price_inc_tax;
        $variation -> deleted_at = $variation_deleted_at;
        $variation -> combo_variations = $variation_combo;
        $variation ->save();
        return $variation;
        }
        catch (\Exception $e) {
            DB::rollBack();
            Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
        }

    }
}
