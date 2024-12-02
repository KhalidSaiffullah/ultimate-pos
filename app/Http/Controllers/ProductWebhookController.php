<?php

namespace App\Http\Controllers;

use App\Product;
use App\ProductVariation;
use App\Variation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;


class ProductWebhookController extends Controller
{
    public function ProductcreatedController(Request $request)
    {
        try {
            // Get the data from the request

            $data = $request->all();
            //$filename = 'product_' . time() . '.json';
            //Storage::put('/product_upload_create_log/' . $filename, json_encode($data));

            if (isset($data['status']) && $data['status'] === 'publish') {
                if ($data['type'] === 'variation' || $data['type'] === 'variable') {
                    //Storage::put('/product_upload_create_log/' . $filename, json_encode($data));
                    if ($data['parent_id'] > 0) {
                        $product = Product::where('woocommerce_product_id', $data['parent_id'])
                            ->get();
                        $count = count($product);
                        if ($count > 0) {
                            if ($product[0]->type === 'single') {
                                //$filename = 'webhook_data_sin_' . time() . '.json';
                                //Storage::put('/product_upload_create_log/' . $filename, json_encode($data));

                                $product[0]->type = 'variable';
                                $product[0]->save();
                                //return $product[0]->id;
                                $productvariation = ProductVariation::where('product_id', $product[0]->id)
                                    ->get();
                                $variations = Variation::where('product_variation_id', $productvariation[0]->id)
                                    ->get();
                                //return $variations;
                                //dd();
                                $variations[0]->delete();
                                $variation = new Variation();
                                $variation->name = $data['name'];
                                $variation->product_id = $product[0]->id;
                                $variation->sub_sku = $product[0]->id;
                                $variation->product_variation_id = $productvariation[0]->id;
                                $variation->default_purchase_price = $data['sale_price'];
                                // $variation ->dpp_inc_tax = $this->num_uf($data['price']);
                                // $variation ->profit_percent = $this->num_uf($data['price']);
                                $variation->default_sell_price = $data['regular_price'];
                                $variation->sell_price_inc_tax = $data['price'];
                                $variation->woocommerce_variation_id = $data['id'];
                                $variation->save();
                                return Response::json(['message' => 'Varient Product save successfully'], 200);
                            } else {
                                $productvariation = ProductVariation::where('product_id', $product[0]->id)
                                    ->get();
                                $variations = Variation::where('woocommerce_variation_id', $data['id'])
                                    ->get();
                                $countvariation = count($variations);
                                if ($countvariation > 0) {
                                    $variations[0]->name = $data['name'];
                                    $variations->sub_sku = $data['sku'] ? $data['sku'] : $variations->sub_sku;
                                    $variations[0]->default_purchase_price = $data['sale_price'];
                                    // $variats[0]ion ->dpp_inc_tax = $this->num_uf($data['price']);
                                    // $variats[0]ion ->profit_percent = $this->num_uf($data['price']);
                                    $variations[0]->default_sell_price = $data['regular_price'];
                                    $variations[0]->sell_price_inc_tax = $data['price'];
                                    $variations[0]->save();
                                    return Response::json(['message' => 'Varient Product updated successfully'], 200);
                                } else {
                                    $variation = new Variation();
                                    $variation->name = $data['name'];
                                    $variation->product_id = $product[0]->id;
                                    $variation->sub_sku = $data['sku'] ? $data['sku'] : '';
                                    $variation->product_variation_id = $productvariation[0]->id;
                                    $variation->default_purchase_price = $data['sale_price'];
                                    // $variation ->dpp_inc_tax = $this->num_uf($data['price']);
                                    // $variation ->profit_percent = $this->num_uf($data['price']);
                                    $variation->default_sell_price = $data['regular_price'];
                                    $variation->sell_price_inc_tax = $data['price'];
                                    $variation->woocommerce_variation_id = $data['id'];
                                    $variation->save();
                                    return Response::json(['message' => 'Varient Product created successfully'], 200);
                                }
                            }
                        } else {
                            $woocommerce = new \Automattic\WooCommerce\Client(
                                'https://lavishta.nafs.tech',
                                // Your store URL
                                'ck_653e414825812de689dbd3ee8a7abf56be76964e',
                                'cs_53422c04b4cb267a8c5ec7941bd2a65deb48207c',
                                [
                                    'wp_api' => true,
                                    // Enable the WP REST API integration
                                    'version' => 'wc/v3' // WooCommerce REST API version
                                ]
                            );


                            $productss = $woocommerce->get('products/' . $data['parent_id']);
                            $jsondata = json_encode($productss);
                            $productdata = json_decode($jsondata);

                            // foreach ($productdata as $productitem) {
                            //     $newvariant_product = new Product(
                            //         [
                            //             'name' => $productitem->name,
                            //             'business_id' => 1,
                            //             'type' => $productitem->type,
                            //             'unit_id' => 1,
                            //             'brand_id' => 1,
                            //             'category_id' => 1,
                            //             'sub_category_id' => 1,
                            //             'enable_stock' => $productitem->true,
                            //             'sku' => $productitem->sku,
                            //             'slug' => $productitem->slug,
                            //             'product_description' => $productitem->description,
                            //             'created_by' => 1,
                            //             'woocommerce_product_id' => $productitem->id,




                            //         ]
                            //     );
                            $newvariant_product = new Product();
                            $newvariant_product->name = $productdata['name'];
                            $newvariant_product->business_id = 1;
                            $newvariant_product->type = 'variable';
                            $newvariant_product->unit_id = 1;
                            $newvariant_product->brand_id = 1;
                            $newvariant_product->category_id = 1;
                            $newvariant_product->sub_category_id = 1;
                            $newvariant_product->enable_stock = false;
                            $newvariant_product->sku = $productdata['sku'];
                            $newvariant_product->slug = $productdata['slug'];
                            $newvariant_product->save();

                            $product_variation = new ProductVariation();
                            $product_variation->name = $productdata['name'];
                            $product_variation->product_id = $newvariant_product->id;
                            $product_variation->is_dummy = 1;
                            $product_variation->save();



                            // $newvariant_product_variation = new Variation(
                            //     [
                            //         'name' => $productitem->name,
                            //         'product_id' => $productitem->id,
                            //         'sub_sku' => $productitem->id,
                            //         'Product_variation_id' => $productitem->id,
                            //         'default_purchase_price' => $productitem->price,
                            //         'default_sell_price' => $productitem->price,
                            //         'sell_price_inc_tax' => $productitem->price,
                            //     ]
                            // );

                            //$newvariant_product_variation->save();

                        }
                    } else {
                        //for not exists parent id
                        // check the existency of the product
                        $product = Product::where('woocommerce_product_id', $data['id'])
                            ->get();
                        $count = count($product);
                        if ($count > 0) {

                            //$filename = 'count' . time() . '.txt';
                            //Storage::put('/product_upload_create_log/' . $filename, $count);

                            $product[0]->sku = $data['sku'];
                            $product[0]->name = $data['name'];
                            $product[0]->type = $data['type'];
                            $product[0]->product_description = $data['description'];
                            $product[0]->save();
                            $product_variations = ProductVariation::where('product_id', $product[0]->id)
                                ->get();
                            $variationsForProduct = Variation::where('product_variation_id', $product_variations[0]->id)
                                ->get();
                            //return $variationsForProduct;
                            $numberArr = [];
                            foreach ($variationsForProduct as $vp) {
                                array_push($numberArr, $vp->woocommerce_variation_id);
                            }
                            // if (count($variationsForProduct) < count($data['variations'])) {
                            //     $smaller = $variationsForProduct;
                            //     $larger = $data['variations'];
                            // } else {
                            //     $smaller = $data['variations'];
                            //     $larger = $variationsForProduct;
                            // }
                            // $similar = array_intersect($smaller, $larger);
                            // $different = array_diff(array_merge($smaller, $larger), $similar);
                            $smaller = [];
                            $larger = [];
                            if (count($numberArr) < count($data['variations'])) {
                                $smaller = $numberArr;
                                $larger = $data['variations'];
                                // return $smaller;

                            } else {

                                $smaller = $data['variations'];
                                $larger = $numberArr;
                            }
                            // return $larger;
                            $similar = [];
                            $different = [];
                            if (is_array($smaller) && is_array($larger)) {
                                $similar = array_intersect($smaller, $larger);
                                $different = array_diff(array_merge($smaller, $larger), $similar);
                                // return $different;
                            } else {

                            }
                            foreach ($different as $dif) {
                                if (in_array($dif, $data['variations'])) {
                                    $woocommerce = new \Automattic\WooCommerce\Client(
                                        'https://lavishta.nafs.tech',
                                        // Your store URL
                                        'ck_653e414825812de689dbd3ee8a7abf56be76964e',
                                        'cs_53422c04b4cb267a8c5ec7941bd2a65deb48207c',
                                        [
                                            'wp_api' => true,
                                            // Enable the WP REST API integration
                                            'version' => 'wc/v3' // WooCommerce REST API version
                                        ]
                                    );
                                    $productssv = $woocommerce->get('products/' . $dif);

                                    /*$filename = 'webhook_data__' . time() . '.json';
                                    Storage::put('/product_upload_create_log/' . $dif.$filename, json_encode($data));*/

                                    $new_variation = new Variation();
                                    $new_variation->name = $productssv->name;
                                    $new_variation->product_id = $product[0]->id;
                                    $new_variation->sub_sku = $productssv->sku;
                                    $new_variation->product_variation_id = $product_variations[0]->id;
                                    $new_variation->default_purchase_price = $productssv->price;
                                    //new_$variation ->dpp_inc_tax = $this->num_uf($data['price']);
                                    //new_$variation ->profit_percent = $this->num_uf($data['price']);
                                    $new_variation->default_sell_price = $productssv->price;
                                    $new_variation->sell_price_inc_tax = $productssv->price;
                                    $new_variation->woocommerce_variation_id = $productssv->id;
                                    $new_variation->save();
                                } else {
                                    $variations = Variation::where('woocommerce_variation_id', $dif)
                                        ->get();
                                    //return $variations;
                                    //dd();
                                    $variations[0]->delete();
                                }
                            }
                        } else {
                            //$filename = 'webhook_data_var1_' . time() . '.json';
                            //Storage::put('/product_upload_create_log/' . $filename, json_encode($data));

                            if (count($request->variations) > 0) {

                                $product_create = new Product();
                                $product_create->name = $data['name'];
                                $product_create->business_id = 1;
                                $product_create->type = $data['type'];
                                $product_create->unit_id = 1;
                                $product_create->brand_id = 1;
                                $product_create->category_id = 1;
                                $product_create->sub_category_id = 1;
                                $product_create->enable_stock = false;
                                $product_create->sku = $data['sku'];
                                $product_create->slug = $data['slug'];
                                $product_create->product_description = $data['description'];
                                $product_create->created_by = 1;
                                $product_create->woocommerce_product_id = $data['id'];
                                $product_create->product_description = $data['description'];
                                $product_create->created_by = 1;
                                // $product_create->woocommerce_product_id = $variationId;
                                // Save the product instance to generate an ID
                                $product_create->save();
                                $productVariation = new ProductVariation();
                                $productVariation->product_id = $product_create->id;
                                $productVariation->name = $data['name'];
                                $productVariation->is_dummy = 1;
                                $productVariation->save();
                                /*$ck =config('WOOCOMMERCE_API_KEY');
                                $cs =config('WOOCOMMERCE_API_KEY');
                                $url = config('WOOCOMMERCE_API_URL');
                                $cred = $ck. $cs . $url;*/
                                //Storage::put('/product_upload_create_log/' . 'apikeyresponse', $cred );




                                foreach ($request->variations as $variationId) {




                                    try {
                                        $woocommerce = new \Automattic\WooCommerce\Client(
                                            'https://lavishta.nafs.tech',
                                            // Your store URL
                                            'ck_653e414825812de689dbd3ee8a7abf56be76964e',
                                            'cs_53422c04b4cb267a8c5ec7941bd2a65deb48207c',
                                            [
                                                'wp_api' => true,
                                                // Enable the WP REST API integration
                                                'version' => 'wc/v3' // WooCommerce REST API version
                                            ]
                                        );
                                    } catch (Exception $e) {
                                        $message = 'Error accessing WooCommerce API: ' . $e->getMessage();
                                        $filename = 'error_log_of_product_sync_' . time() . '.txt';
                                        Storage::disk('public')->append($filename, $message);

                                    }

                                    $variant_product_details = $woocommerce->get('products/' . $variationId);
                                    //Storage::put('/product_upload_create_log/' . 'skuHas'.$filename, json_encode($variant_product_details));
                                    $variation = new Variation();
                                    $variation->name = $variant_product_details->name;
                                    $variation->product_id = $product_create->id;
                                    if (isset($variant_product_details->sku)) {
                                        $variation->sub_sku = $variant_product_details->sku;
                                    } else {
                                        $variation->sub_sku = 'v-sk' . $variant_product_details->id;
                                    }
                                    $variation->product_variation_id = $productVariation->id;
                                    $variation->default_purchase_price = $variant_product_details->price;
                                    $variation->default_sell_price = $variant_product_details->sale_price;
                                    $variation->sell_price_inc_tax = $variant_product_details->sale_price;
                                    $variation->woocommerce_variation_id = $variationId;
                                    $variation->save();
                                }
                            }
                        }
                    }
                } else {

                    //Storage::put('/product_upload_create_log/' . $filename, json_encode($data));
                    $product = Product::where('woocommerce_product_id', $data['id'])
                        ->get();
                    $count = count($product);
                    if ($count > 0) {
                        if (isset($data['sku'])) {
                            $product[0]->sku = $data['sku'];
                            $product[0]->name = $data['name'];
                            $product[0]->save();
                            return Response::json(['message' => 'Product Updated Successfully'], 200);
                        } else {

                            // $product[0]->sku = $data['id'];

                            $product[0]->name = $data['name'];
                            $product[0]->save();
                            return Response::json(['message' => 'Product Updated Successfully'], 200);
                        }
                    } else {
                        $product_create = new Product();
                        $product_create->name = $data['name'];
                        $product_create->business_id = 1;
                        $product_create->type = 'single';
                        $product_create->unit_id = 1;
                        $product_create->brand_id = 1;
                        $product_create->category_id = 1;
                        $product_create->sub_category_id = 1;
                        $product_create->enable_stock = false;
                        if (isset($data['sku'])) {
                            $product_create->sku = $data['sku'];
                        } else {
                            $product_create->sku = $data['id'];

                        }
                        $product_create->slug = $data['slug'];
                        // $product_create->image=$data['images[0]'];
                        $product_create->product_description = $data['description'];
                        $product_create->created_by = 1;
                        $product_create->woocommerce_product_id = $data['id'];
                        $product_create->save();
                        $product_variation = new ProductVariation();
                        $product_variation->name = $data['name'];
                        $product_variation->product_id = $product_create->id;
                        $product_variation->is_dummy = 1;
                        $product_variation->save();
                        $variation = new Variation();
                        $variation->name = $data['name'];
                        $variation->product_id = $product_create->id;
                        $variation->sub_sku = $product_create->id;
                        $variation->product_variation_id = $product_variation->id;
                        $variation->default_purchase_price = $data['price'];
                        // $variation ->dpp_inc_tax = $this->num_uf($data['price']);
                        // $variation ->profit_percent = $this->num_uf($data['price']);
                        $variation->default_sell_price = $data['price'];
                        $variation->sell_price_inc_tax = $data['price'];
                        $variation->save();
                        return Response::json(['message' => 'Product Created Successfully'], 200);
                    }
                }
            } else {
                return Response::json(['message' => 'Webhook data not saved.'], 200);
            }
        } catch (Exception $e) {
            $data = $request->all();
            $filename = 'product_webhook_error' . time() . '.json';
            Storage::put('/product_upload_create_log/' . $filename, json_encode($data));
            //return Response::json(['error' => $e->getMessage()], 500);
            return Response::json(['message' => 'Webhook data not saved.'], 200);
        }
        // Return a response to the webhook sender
    }
}