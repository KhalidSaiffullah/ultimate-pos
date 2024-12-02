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
                                $productvariation = ProductVariation::where('product_id', $product[0]->id)->get();
                                $variations = Variation::where('product_variation_id', $productvariation[0]->id)->get();
                                //return $variations;
                                //dd();
                                $variations[0]->delete();

                                // Call the nameCreateVariation function to create the new variation
                                return $this->nameCreateVariation($data, $product, $productvariation);
                            }
                        } else {
                            return $this->createNewVariantProduct($data);
                        }
                    } else {
                        $this->handleParentIdNotExists($data);
                    }
                } else {
                    $newProduct = $this->createNewProduct($data);
                    return Response::json(['message' => 'Product Created Successfully'], 200);
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






























    // Define the private function nameCreateVariation
    private function nameCreateVariation($data, $product, $productvariation)
    {
        $variations = Variation::where('woocommerce_variation_id', $data['id'])->get();
        $countVariation = count($variations);

        if ($countVariation > 0) {
            $variation = $variations[0];
            $variation->name = $data['name'];
            $variation->sub_sku = $data['sku'] ? $data['sku'] : $variation->sub_sku;
            $variation->default_purchase_price = $data['sale_price'];
            // $variation->dpp_inc_tax = $this->num_uf($data['price']);
            // $variation->profit_percent = $this->num_uf($data['price']);
            $variation->default_sell_price = $data['regular_price'];
            $variation->sell_price_inc_tax = $data['price'];
            $variation->save();

            return Response::json(['message' => 'Variant Product updated successfully'], 200);
        }

        $variation = new Variation();
        $variation->name = $data['name'];
        $variation->product_id = $product[0]->id;
        $variation->sub_sku = $product[0]->id;
        $variation->product_variation_id = $productvariation[0]->id;
        $variation->default_purchase_price = $data['sale_price'];
        // $variation->dpp_inc_tax = $this->num_uf($data['price']);
        // $variation->profit_percent = $this->num_uf($data['price']);
        $variation->default_sell_price = $data['regular_price'];
        $variation->sell_price_inc_tax = $data['price'];
        $variation->woocommerce_variation_id = $data['id'];
        $variation->save();

        return Response::json(['message' => 'Variant Product saved successfully'], 200);
    }
    private function createNewVariantProduct($data)
    {
        $woocommerce = new \Automattic\WooCommerce\Client(
            'https://lavishta.nafs.com/woo_test',
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

        // You can also create the default variation here if needed
        // $newvariant_product_variation = new Variation([
        //     'name' => $productitem->name,
        //     'product_id' => $productitem->id,
        //     'sub_sku' => $productitem->id,
        //     'Product_variation_id' => $productitem->id,
        //     'default_purchase_price' => $productitem->price,
        //     'default_sell_price' => $productitem->price,
        //     'sell_price_inc_tax' => $productitem->price,
        // ]);
        // $newvariant_product_variation->save();
    }
    // Define the new private function handleParentIdNotExists
    private function handleParentIdNotExists($data)
    {
        // Check the existence of the product
        $product = Product::where('woocommerce_product_id', $data['id'])->get();
        $count = count($product);

        if ($count > 0) {
            $product[0]->sku = $data['sku'];
            $product[0]->name = $data['name'];
            $product[0]->type = $data['type'];
            $product[0]->product_description = $data['description'];
            $product[0]->save();

            $product_variations = ProductVariation::where('product_id', $product[0]->id)->get();
            $variationsForProduct = Variation::where('product_variation_id', $product_variations[0]->id)->get();

            $numberArr = [];
            foreach ($variationsForProduct as $vp) {
                array_push($numberArr, $vp->woocommerce_variation_id);
            }

            $smaller = [];
            $larger = [];

            if (count($numberArr) < count($data['variations'])) {
                $smaller = $numberArr;
                $larger = $data['variations'];
            } else {
                $smaller = $data['variations'];
                $larger = $numberArr;
            }

            $similar = [];
            $different = [];

            if (is_array($smaller) && is_array($larger)) {
                $similar = array_intersect($smaller, $larger);
                $different = array_diff(array_merge($smaller, $larger), $similar);
            } else {
                // Handle the case where smaller or larger is not an array
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

                    $new_variation = new Variation();
                    $new_variation->name = $productssv->name;
                    $new_variation->product_id = $product[0]->id;
                    $new_variation->sub_sku = $productssv->sku;
                    $new_variation->product_variation_id = $product_variations[0]->id;
                    $new_variation->default_purchase_price = $productssv->price;
                    $new_variation->default_sell_price = $productssv->price;
                    $new_variation->sell_price_inc_tax = $productssv->price;
                    $new_variation->woocommerce_variation_id = $productssv->id;
                    $new_variation->save();
                } else {
                    $variations = Variation::where('woocommerce_variation_id', $dif)->get();
                    $variations[0]->delete();
                }
            }
        } else {
            if (count($request->variations) > 0) {
                $product_create = new Product();
                // Set product properties
                $product_create->save();
                $productVariation = new ProductVariation();
                // Set product variation properties
                $productVariation->save();

                foreach ($request->variations as $variationId) {
                    // Handle each variation
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
                        // Handle WooCommerce API error
                        $message = 'Error accessing WooCommerce API: ' . $e->getMessage();
                        $filename = 'error_log_of_product_sync_' . time() . '.txt';
                        Storage::disk('public')->append($filename, $message);
                    }

                    $variant_product_details = $woocommerce->get('products/' . $variationId);

                    $variation = new Variation();
                    // Set variation properties based on $variant_product_details
                    $variation->save();
                }
            }
        }
    }
    private function createNewProduct($data)
    {
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
        $variation->default_sell_price = $data['price'];
        $variation->sell_price_inc_tax = $data['price'];
        $variation->save();

        return $product_create;
    }
}