<?php

namespace Modules\Woocommerce\Http\Controllers;

use App\LogTable;
use DB;
use App\Product;
use App\Business;
use App\Variation;
use App\Transaction;
use App\PurchaseLine;
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
use App\Brands;
use App\Unit;

class WoocommerceWebhookController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $woocommerceUtil;
    protected $moduleUtil;
    protected $transactionUtil;
    protected $productUtil;

    /**
     * Constructor
     *
     * @param WoocommerceUtil $woocommerceUtil
     * @return void
     */
    public function __construct(WoocommerceUtil $woocommerceUtil, ModuleUtil $moduleUtil, TransactionUtil $transactionUtil, ProductUtil $productUtil)
    {
        $this->woocommerceUtil = $woocommerceUtil;
        $this->moduleUtil = $moduleUtil;
        $this->transactionUtil = $transactionUtil;
        $this->productUtil = $productUtil;
    }

    /**
     * Function to create sale from woocommerce webhook request.
     * @return Response
     */
    public function orderCreated(Request $request, $business_id)
    {
        try {
            // Save the data to a JSON file
            $filename = 'webhook_data_order' . time() . '.json';
            $payload = $request->json()->all();
            // Get the data from the request
            $data = $request->query->all();
            //end edit
            $payload = $request->getContent();

            $business = Business::findOrFail($business_id);
            $is_valid_request = $this->isValidWebhookRequest($request, $business->woocommerce_wh_oc_secret);

            if (!$is_valid_request) {
                \Log::emergency('Woocommerce webhook signature mismatch');
            } else {
                $user_id = $business->owner->id;
                $woocommerce_api_settings = $this->woocommerceUtil->get_api_settings($business_id);
                $business_data = [
                    'id' => $business_id,
                    'accounting_method' => $business->accounting_method,
                    'location_id' => $woocommerce_api_settings->location_id,
                    'business' => $business,
                ];

                $order_data = json_decode($payload);
                Storage::put('/webhook_data/' . 'success_' . $filename, json_encode($order_data));
                // return $order_data->id;

                DB::beginTransaction();


                if (isset($order_data->woo_product_brand)) {
                    $productExistscheck = $this->isProductsExists($order_data->line_items, $business_id, $woocommerce_api_settings->location_id, $order_data->woo_product_brand);
                } else {
                    $productExistscheck = $this->isProductsExists($order_data->line_items, $business_id, $woocommerce_api_settings->location_id, null);
                }
                //return $productExistscheck;

                $created = $this->woocommerceUtil->createNewSaleFromOrder($business_id, $user_id, $order_data, $business_data);
                // return json_encode($created);
                // return $created;
                $create_error_data = $created !== true ? $created : [];
                $created_data[] = $order_data->number;
                // return $$created_data;

                //Create log
                if (!empty($created_data)) {
                    $this->woocommerceUtil->createSyncLog($business_id, $user_id, 'orders', 'created', $created_data, $create_error_data);
                }
                DB::commit();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());
            Storage::put('/webhook_data/' . $filename, 'Message:' . $e->getMessage());
        }
    }

    /**
     * Function to update sale from woocommerce webhook request.
     * @return Response
     */
    public function orderUpdated(Request $request, $business_id)
    {
        try {
            $business = Business::findOrFail($business_id);
            $payload = $request->getContent();

            $is_valid_request = $this->isValidWebhookRequest($request, $business->woocommerce_wh_ou_secret);

            if (!$is_valid_request) {
                \Log::emergency('Woocommerce webhook signature mismatch');
            } else {
                $user_id = $business->owner->id;
                $woocommerce_api_settings = $this->woocommerceUtil->get_api_settings($business_id);
                $business_data = [
                    'id' => $business_id,
                    'accounting_method' => $business->accounting_method,
                    'location_id' => $woocommerce_api_settings->location_id,
                ];

                $order_data = json_decode($payload);

                $sell = Transaction::where('business_id', $business_id)
                    ->where('woocommerce_order_id', $order_data->id)
                    ->with('sell_lines', 'sell_lines.product', 'payment_lines')
                    ->first();

                if (!empty($sell)) {
                    DB::beginTransaction();

                    $updated = $this->woocommerceUtil->updateSaleFromOrder($business_id, $user_id, $order_data, $sell, $business_data);
                    // return $updated;
                    $updated_data[] = $order_data->number;
                    $update_error_data = $updated !== true ? $updated : [];

                    //Create log
                    if (!empty($updated_data)) {
                        $this->woocommerceUtil->createSyncLog($business_id, $user_id, 'orders', 'updated', $updated_data, $update_error_data);
                    }
                    DB::commit();
                }
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());
        }
    }

    /**
     * Function to delete sale from woocommerce webhook request.
     * @return Response
     */
    public function orderDeleted(Request $request, $business_id)
    {
        try {
            $business = Business::findOrFail($business_id);
            $payload = $request->getContent();

            $is_valid_request = $this->isValidWebhookRequest($request, $business->woocommerce_wh_od_secret);

            if (!$is_valid_request) {
                \Log::emergency('Woocommerce webhook signature mismatch');
            } else {
                $user_id = $business->owner->id;
                //$woocommerce_api_settings = $this->woocommerceUtil->get_api_settings($business_id);

                $order_data = json_decode($payload);

                $transaction = Transaction::where('business_id', $business_id)
                    ->where('woocommerce_order_id', $order_data->id)
                    ->with('sell_lines')
                    ->first();

                $log_data[] = $transaction->invoice_no;

                DB::beginTransaction();

                if (!empty($transaction)) {
                    $status_before = $transaction->status;
                    $transaction->status = 'draft';
                    $transaction->save();

                    $input['location_id'] = $transaction->location_id;
                    foreach ($transaction->sell_lines as $sell_line) {
                        $input['products']['transaction_sell_lines_id'] = $sell_line->id;
                        $input['products']['product_id'] = $sell_line->product_id;
                        $input['products']['variation_id'] = $sell_line->variation_id;
                        $input['products']['quantity'] = $sell_line->quantity;
                    }

                    //Update product stock
                    $this->productUtil->adjustProductStockForInvoice($status_before, $transaction, $input);

                    $business = [
                        'id' => $business_id,
                        'accounting_method' => $business->accounting_method,
                        'location_id' => $transaction->location_id,
                    ];
                    $this->transactionUtil->adjustMappingPurchaseSell($status_before, $transaction, $business);
                }

                //Create log
                if (!empty($log_data)) {
                    $this->woocommerceUtil->createSyncLog($business_id, $user_id, 'orders', 'deleted', $log_data);
                }

                DB::commit();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());
        }
    }

    /**
     * Function to restore sale from woocommerce webhook request.
     * @return Response
     */
    public function orderRestored(Request $request, $business_id)
    {
        try {
            $business = Business::findOrFail($business_id);
            $payload = $request->getContent();

            $is_valid_request = $this->isValidWebhookRequest($request, $business->woocommerce_wh_or_secret);

            if (!$is_valid_request) {
                \Log::emergency('Woocommerce webhook signature mismatch');
            } else {
                $user_id = $business->owner->id;
                $woocommerce_api_settings = $this->woocommerceUtil->get_api_settings($business_id);
                $business_data = [
                    'id' => $business_id,
                    'accounting_method' => $business->accounting_method,
                    'location_id' => $woocommerce_api_settings->location_id,
                    'business' => $business,
                ];

                $order_data = json_decode($payload);
                $sell = Transaction::where('business_id', $business_id)
                    ->where('woocommerce_order_id', $order_data->id)
                    ->with('sell_lines', 'sell_lines.product', 'payment_lines')
                    ->first();

                DB::beginTransaction();
                //If sell not deleted restore from draft else create new sale
                if (!empty($sell)) {
                    $updated = $this->woocommerceUtil->updateSaleFromOrder($business_id, $user_id, $order_data, $sell, $business_data);

                    $updated_data[] = $order_data->number;
                    $update_error_data = $updated !== true ? $updated : [];

                    //Create log
                    if (!empty($updated_data)) {
                        $this->woocommerceUtil->createSyncLog($business_id, $user_id, 'orders', 'restored', $updated_data, $update_error_data);
                    }
                } else {
                    $created = $this->woocommerceUtil->createNewSaleFromOrder($business_id, $user_id, $order_data, $business_data);

                    $create_error_data = $created !== true ? $created : [];
                    $created_data[] = $order_data->number;

                    //Create log
                    if (!empty($created_data)) {
                        $this->woocommerceUtil->createSyncLog($business_id, $user_id, 'orders', 'created', $created_data, $create_error_data);
                    }
                }

                DB::commit();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());
        }
    }

    private function isValidWebhookRequest($request, $secret)
    {
        $signature = $request->header('x-wc-webhook-signature');

        $payload = $request->getContent();

        $calculated_hmac = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        if ($signature != $calculated_hmac) {
            return true; //fahim
        } else {
            return true;
        }
    }
    //get business details
    private function getBusinessDetails($business_id)
    {
        try {
            $details = Business::Find($business_id);
            return $details;
        } catch (\Exception $e) {
        }
    }
    //product check if avilable or not
    private function isProductsExists($line_items, $business_id, $location_id, $brandData)
    {
        //     if(empty($brandData) || is_null($brandData)){
        //     return 'test';
        // }
        // return $brandData[0]->name;
        $filename = 'webhook_data_order' . time() . '.json';
        try {
            $businessDetails = $this->getBusinessDetails($business_id);

            $woocommerceAppUrl = '';
            $woocommerceConsumerKey = '';
            $woocommerceConsumerSecret = '';
            if (!is_null($businessDetails)) {
                $woocommerceApiSettings = json_decode($businessDetails->woocommerce_api_settings);
                if (!is_null($woocommerceApiSettings->woocommerce_app_url) && !is_null($woocommerceApiSettings->woocommerce_consumer_key) && !is_null($woocommerceApiSettings->woocommerce_consumer_secret)) {
                    $woocommerceAppUrl = $woocommerceApiSettings->woocommerce_app_url;
                    $woocommerceConsumerKey = $woocommerceApiSettings->woocommerce_consumer_key;
                    $woocommerceConsumerSecret = $woocommerceApiSettings->woocommerce_consumer_secret;
                } else {
                    dd($businessDetails);
                }
            } else {
                dd($businessDetails);
            }
            $woocommerce = new \Automattic\WooCommerce\Client(
                $woocommerceAppUrl,
                // Your store URL
                $woocommerceConsumerKey,
                $woocommerceConsumerSecret,
                [
                    'wp_api' => true,
                    // Enable the WP REST API integration
                    'version' => 'wc/v3',
                    // WooCommerce REST API version
                ],
            );

            $brandName = $brandData[0]->brand_name;
            // return $brandName;




            foreach ($line_items as $item) {
                // Storage::put('/webhook_data/' . $filename, $item);
                $productExistsCheck = Product::where('woocommerce_product_id', $item->product_id)
                    ->where('business_id', $business_id)
                    ->get();

                $count = count($productExistsCheck);

                $line_items_value = $item->quantity;
                $brandName = !empty($brandName) ? $brandName : 'test3';
                if (empty($brandName) || is_null($brandName)) {
                    $brandExistingCheck = Brands::where('name', 'no brand')
                        ->where('business_id', $business_id)
                        ->get();

                    if (empty($brandExistingCheck) || is_null($brandExistingCheck) || count($brandExistingCheck) === 0) {
                        $brandCreate = $this->brandCreate('no brand', $business_id, 1);
                        $brandId = $brandCreate->id;
                    } else {
                        $brandId = $brandExistingCheck[0]->id;
                    }
                } else {
                    $brandExistingCheck = Brands::where('name', $brandName)
                        ->where('business_id', $business_id)
                        ->get();
                    if (empty($brandExistingCheck) || is_null($brandExistingCheck) || count($brandExistingCheck) === 0) {
                        $brandCreate = $this->brandCreate($brandName, $business_id, 1);
                        $brandId = $brandCreate->id;
                        // return $brandId;



                    } else {
                        $brandId = $brandExistingCheck[0]->id;
                        // return $brandId;
                    }
                }

                $productid = 0;

                if ($count == 0) {
                    $productss = $woocommerce->get('products/' . $item->product_id);
                    $jsondata = json_encode($productss);
                    $productdata = json_decode($jsondata);

                    // $brands = $productdata->brands;

                    // if (!empty($brands)) {
                    //     foreach ($brands as $brand) {
                    //         $existingBrand = Brands::where('name', $brand->name)->first();

                    //         if ($existingBrand) {
                    //             $brandId = $existingBrand->id;
                    //         } else {
                    //             // $newBrand = Brands::create([
                    //             //     'name' => $brand->name,
                    //             //     'slug' => $brand->slug,
                    //             //     'business_id' => 1,
                    //             //     'created_by' => 1
                    //             // ]);
                    //             // $brandId = $newBrand->id;

                    //             $brand_create = new Brands();
                    //             $brand_create->name = $brand->name;
                    //             $brand_create->slug = $brand->slug;
                    //             $brand_create->business_id = $business_id;
                    //             $brand_create->created_by = 1;
                    //             $brand_create->save();

                    //             $brandId = $brand_create->id;
                    //         }
                    //     }
                    // } else {
                    //     $brandIds = Brands::where('business_id', $business_id)->first();
                    //     $brandId = $brandIds->id;
                    // }

                    $unitId = Unit::where('business_id', $business_id)->first();

                    $product_create = new Product();
                    $product_create->name = $productdata->name;
                    $product_create->business_id = $business_id;
                    if ($item->variation_id == 0) {
                        $product_create->type = 'single';
                    } else {
                        $product_create->type = 'variable';
                    }
                    $product_create->unit_id = $unitId->id;
                    $product_create->brand_id = $brandId;
                    $product_create->category_id = 1;
                    $product_create->sub_category_id = 1;
                    $product_create->enable_stock = $productdata->manage_stock === true ? true : false;
                    if (isset($productdata->low_stock_amount)) {
                        $product_create->alert_quantity = $productdata->low_stock_amount;
                    } else {
                        $product_create->alert_quantity = 0;
                    }
                    if (isset($productdata->sku)) {
                        $product_create->sku = $productdata->sku;
                    } else {
                        $product_create->sku = $productdata->id;
                    }
                    $product_create->slug = $productdata->slug;
                    // $product_create->image=$data->images[0];
                    $product_create->product_description = $productdata->description;
                    $product_create->created_by = 1;
                    $product_create->woocommerce_product_id = $productdata->id;
                    $product_create->save();

                    $productid = $product_create->id;

                    // $filename = '1st_response' . time() . '.json';
                    // Storage::put('product_upload_create_log/' . $filename, $jsondata);

                    // $filename = '2nd_response' . time() . '.json';
                    // Storage::put('product_upload_create_log/' . $filename, $product_create);

                    //  product_locations table
                    DB::table('product_locations')->insert([
                        'product_id' => $product_create->id,
                        'location_id' => $location_id,
                    ]);

                    $product_variation = new ProductVariation();
                    $product_variation->name = $productdata->name;
                    $product_variation->product_id = $product_create->id;
                    $product_variation->is_dummy = 1;
                    $product_variation->save();

                    if ($item->variation_id > 0) {
                        $variationdataresponse = $woocommerce->get('products/' . $item->variation_id);
                        $variationjsondata = json_encode($variationdataresponse);
                        $varproductdata = json_decode($variationjsondata);

                        $variation = new Variation();
                        $variation->name = $varproductdata->name;
                        $variation->product_id = $product_create->id;
                        if (isset($varproductdata->sku)) {
                            $variation->sub_sku = $varproductdata->sku;
                        } else {
                            $variation->sub_sku = $product_create->id;
                        }
                        $variation->product_variation_id = $product_variation->id;
                        $variation->default_purchase_price = $varproductdata->price;
                        $variation->default_sell_price = $varproductdata->price;
                        $variation->sell_price_inc_tax = $varproductdata->price;
                        // $variation->default_sell_price = $varproductdata->sale_price;
                        // $variation->sell_price_inc_tax = $varproductdata->sale_price;
                        $variation->woocommerce_variation_id = $varproductdata->id;
                        $variation->save();

                        if ($varproductdata->manage_stock === true && $productdata->manage_stock === false) {
                            $productToUpdate = Product::find($product_create->id);
                            $productToUpdate->enable_stock = true;
                            $productToUpdate->save();
                        }

                        $stockproductquantity = $varproductdata->stock_quantity;
                        $stockproductquantity = $stockproductquantity + $line_items_value;
                        $filename = 'product_quantity_var' . time() . '.txt';
                        Storage::put('product_upload_create_log/' . $filename, $stockproductquantity);

                        $this->createVariationLocationDetails($varproductdata->id, $product_variation->id, $variation->id, $location_id, $stockproductquantity);
                        $total_price = $varproductdata->price * $varproductdata->stock_quantity;
                        $date_time = date('Y-m-d H:i:s');

                        $createtransactions = $this->createTransactions($business_id, $location_id, 'opening_stock', 'received', 'paid', $date_time, $total_price, $date_time, $total_price, 1.0, $productdata->id, 1);
                        $this->createPurchaseline($createtransactions->id, $product_create->id, $variation->id, $stockproductquantity, $varproductdata->price, 0.0, $varproductdata->price, $varproductdata->price);
                    } else {
                        $variation = new Variation();
                        $variation->name = $productdata->name;
                        $variation->product_id = $product_create->id;
                        $variation->sub_sku = $product_create->id;
                        $variation->product_variation_id = $product_variation->id;
                        $variation->default_purchase_price = $productdata->price;
                        // $variation ->dpp_inc_tax = $this->num_uf($data->price);
                        // $variation ->profit_percent = $this->num_uf($data->price);
                        $variation->default_sell_price = $productdata->sale_price;
                        $variation->sell_price_inc_tax = $productdata->sale_price;
                        $variation->save();
                        if ($variation == true) {
                            $product_create = new Product();

                            $product_create->enable_stock = $variation->manage_stock = true;
                        }
                        $stockproductquantity = $productdata->stock_quantity;
                        $stockproductquantity = $stockproductquantity + $line_items_value;

                        $filename = 'product_quantity_sin' . time() . '.txt';
                        Storage::put('product_upload_create_log/' . $filename, $stockproductquantity);

                        $this->createVariationLocationDetails($productdata->id, $product_variation->id, $variation->id, $location_id, $stockproductquantity);
                        $total_price = $product_create->price * $productdata->stock_quantity;
                        $date_time = date('Y-m-d H:i:s');
                        $createtransactions = $this->createTransactions($business_id, $location_id, 'opening_stock', 'received', 'paid', $date_time, $total_price, $date_time, $total_price, 1.0, $productdata->id, 1);

                        $content = "trans id = $createtransactions->id, pro id = " . $productid . ", var = $variation->id, stock = $stockproductquantity, price = $productdata->price, v-price = 0.0000, sprice = $productdata->price, t-price = $productdata->price";

                        $filename = 'signle_product_transactions_details' . time() . '.txt';

                        Storage::put('product_upload_create_log/' . $filename, $content);

                        $this->createPurchaseline($createtransactions->id, $productid, $variation->id, $stockproductquantity, $productdata->price, 0.0, $productdata->price, $productdata->price);
                        // $this->createPurchaseline($createtransactions->id, $product_create->id, $variation->id, $stockproductquantity, $productdata->price, 0.0000, $productdata->price, $productdata->price);
                    }
                } else {
                    if ($item->variation_id > 0) {
                        $productvarcheck = ProductVariation::where('product_id', $productExistsCheck[0]->id)->get();

                        $productvarExistsCheck = Variation::where('woocommerce_variation_id', $item->variation_id)
                            ->where('product_id', $productExistsCheck[0]->id)
                            ->get();
                        $pcount = count($productvarExistsCheck);
                        //return $productvarcheck[0]->id;
                        if ($pcount == 0) {
                            $variationdataresponse = $woocommerce->get('products/' . $item->variation_id);

                            $variationjsondata = json_encode($variationdataresponse);
                            $varproductdata = json_decode($variationjsondata);

                            $variation = new Variation();
                            $variation->name = $varproductdata->name;
                            $variation->product_id = $productExistsCheck[0]->id;
                            $variation->sub_sku = $varproductdata->sku;
                            $variation->product_variation_id = $productvarcheck[0]->id;

                            $variation->default_purchase_price = $varproductdata->price;
                            // $variation ->dpp_inc_tax = $this->num_uf($data->price);
                            // $variation ->profit_percent = $this->num_uf($data->price);
                            $variation->default_sell_price = $varproductdata->sale_price;
                            $variation->sell_price_inc_tax = $varproductdata->sale_price;
                            $variation->woocommerce_variation_id = $varproductdata->id;
                            $variation->save();

                            $stockproductquantity = $varproductdata->stock_quantity;
                            $stockproductquantity += $line_items_value;

                            $filename = 'product_quantity_existing_var_' . time() . '.txt';
                            $logContent = "line items: $line_items_value, stock quantity: $stockproductquantity";

                            Storage::put('product_upload_create_log/' . $filename, $logContent);

                            $mk = $this->createVariationLocationDetails($variation->product_id, $variation->product_variation_id, $variation->id, $location_id, $stockproductquantity);
                            // $this->createVariationLocationDetails($productdata->id, $product_variation->id, $variation->id, 1, $productdata->stock_quantity);
                            $total_price = $varproductdata->price * $varproductdata->stock_quantity;
                            $date_time = date('Y-m-d H:i:s');
                            $createtransactions = $this->createTransactions($business_id, $location_id, 'opening_stock', 'received', 'paid', $date_time, $total_price, $date_time, $total_price, 1.0, $varproductdata->id, 1);
                            $this->createPurchaseline($createtransactions->id, $variation->product_id, $variation->id, $stockproductquantity, $varproductdata->price, 0.0, $varproductdata->price, $varproductdata->price);
                        }
                    }
                }
            }
            return true;
        } catch (\Exception $e) {
            Storage::put('/webhook_data/' . $filename, 'Message:' . $e->getMessage());
        }
    }

    private function createVariationLocationDetails($productId, $productVariationId, $variationId, $locationId, $qtyAvailable)
    {
        try {
            $variationlocationdetails = new VariationLocationDetails();
            $variationlocationdetails->product_id = $productId;
            $variationlocationdetails->product_variation_id = $productVariationId;
            $variationlocationdetails->variation_id = $variationId;
            $variationlocationdetails->location_id = $locationId;
            $variationlocationdetails->qty_available = $qtyAvailable;
            $variationlocationdetails->save();
            return $variationlocationdetails;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());
        }
    }
    private function createTransactions($businessId, $locationId, $type, $status, $paymentStatus, $transactionDate, $totalBeforeTax, $deliveryDate, $finalTotal, $exchangeRate, $openingStockProductId, $createdBy)
    {
        try {
            $createtransactions = new Transaction();
            $createtransactions->business_id = $businessId;
            $createtransactions->location_id = $locationId;
            $createtransactions->type = $type;
            $createtransactions->status = $status;
            $createtransactions->payment_status = $paymentStatus;
            $createtransactions->transaction_date = $transactionDate;
            $createtransactions->total_before_tax = $totalBeforeTax;
            $createtransactions->preferable_delivery_date = $deliveryDate;
            $createtransactions->final_total = $finalTotal;
            $createtransactions->exchange_rate = $exchangeRate;
            $createtransactions->opening_stock_product_id = $openingStockProductId;
            $createtransactions->created_by = $createdBy;
            $createtransactions->save();
            return $createtransactions;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());
        }
    }

    private function createPurchaseline($transaction_id, $product_id, $variation_id, $quantity, $pp_without_discount, $discount_percent, $purchase_price, $purchase_price_inc_tax)
    {
        try {
            $createpurchaseline = new PurchaseLine();
            $createpurchaseline->transaction_id = $transaction_id;
            $createpurchaseline->product_id = $product_id;
            $createpurchaseline->variation_id = $variation_id;
            $createpurchaseline->quantity = $quantity;
            $createpurchaseline->pp_without_discount = $pp_without_discount;
            $createpurchaseline->discount_percent = $discount_percent;
            $createpurchaseline->purchase_price = $purchase_price;
            $createpurchaseline->purchase_price_inc_tax = $purchase_price_inc_tax;
            $createpurchaseline->save();
            return $createpurchaseline;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());
        }
    }
    private function brandCreate($brandValue, $businessId, $created_by)
    {
        try {
            $createBrand = new Brands();
            if (empty($brandValue)) {
                $createBrand->name = 'No brand';
            } else {
                $createBrand->name = $brandValue;
            }
            $createBrand->business_id = $businessId;
            $createBrand->created_by = $created_by;
            $createBrand->save();
            return $createBrand;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::emergency('File:' . $e->getFile() . 'Line:' . $e->getLine() . 'Message:' . $e->getMessage());
        }
    }
}
