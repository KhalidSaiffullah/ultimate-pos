<?php

namespace App\Http\Controllers\Api\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Transformers\ProductResource;
use DB;

use App\Category;
use App\Brands;
use App\Product;
use App\Variation;
use App\SellingPriceGroup;
use App\Storefront;

class ProductController extends ApiController
{
    public function index()
    {
        $filters = request()->only(['brand', 'category', 'location_id', 'sub_category', 'per_page', 'sort_by']);
        $filters['selling_price_group'] = request()->input('selling_price_group') == 1 ? true : false;
        $search = request()->only(['sku', 'name']);
        $products = $this->__getProducts($this->business_id, $filters, $search, true);

        return ProductResource::collection($products);
    }

    public function show($slug)
    {
        $filters['selling_price_group'] = request()->input('selling_price_group') == 1 ? true : false;

        $product = Product::where('business_id', $this->business_id)
            ->where('slug',$slug)
            ->with(['product_variations.variations.variation_location_details', 'brand', 'unit', 'category', 'sub_category', 'product_tax', 'product_variations.variations.media', 'product_locations'])
            ->first();

        return $product;
    }

    public function related($slug)
    {
        $filters = [];
        $product = Product::where('business_id', $this->business_id)
            ->where('slug',$slug)
            ->with(['product_variations.variations.variation_location_details', 'brand', 'unit', 'category', 'sub_category', 'product_tax', 'product_variations.variations.media', 'product_locations'])
            ->first();
        
        $filters['per_page'] = 10;
        if($product){
            $filters['product'] = $product->id;
            if($product->category){
                $filters['category'] = $product->category->slug;
            }
            if($product->sub_category){
                $filters['sub_category'] = $product->sub_category->slug;
            }
        }

        $products = $this->__getProducts($this->business_id, $filters, [], true);

        return ProductResource::collection($products);
    }

    /**
     * Function to query product
     * @return Response
     */
    private function __getProducts($business_id, $filters = [], $search = [], $pagination = false)
    {
        $query = Product::where('business_id', $business_id);

        $with = ['product_variations.variations.variation_location_details', 'brand', 'unit', 'category', 'sub_category', 'product_tax', 'product_variations.variations.media', 'product_locations'];

        if (!empty($filters['category'])) {
            $category = Category::where('slug',$filters['category'])->first();
            if($category){
                $query->where('category_id', $category->id);
            }
        }

        if (!empty($filters['sub_category'])) {
            $sub_category = Category::where('slug',$filters['sub_category'])->first();
            if($sub_category){
                $query->where('sub_category_id', $sub_category->id);
            }
        }

        if (!empty($filters['brand'])) {
            $brands = [];
            foreach(explode(',',$filters['brand']) as $brand_slug){
                $brand = Brands::where('slug',$brand_slug)->first();
                if($brand){
                    $brands[] = $brand->id;
                }
            }
            if(count($brands)>0){
                $query->whereIn('brand_id', $brands);
            }
        }

        if (!empty($filters['sort_by'])) {
            if($filters['sort_by']=='newest'){
                $query->orderBy('created_at','desc');
            }
            if($filters['sort_by']=='trending'){
                $query
                    ->leftJoin('transaction_sell_lines',function($join){
                        $join->on('transaction_sell_lines.product_id','products.id');
                        $join->where('transaction_sell_lines.created_at','>',date("y-m-d",strtotime("-30 day")));
                    })
                    ->groupBy('products.id')
                    ->select('products.*',DB::raw('COUNT(transaction_sell_lines.id) as trend'))
                    ->withCount('transaction_sell_lines')
                    ->orderBy('trend','desc')
                    ->orderBy('transaction_sell_lines_count','desc');
            }
            if($filters['sort_by']=='popularity'){
                $query->withCount('transaction_sell_lines')->orderBy('transaction_sell_lines_count','desc');
            }
            if($filters['sort_by']=='low-high'){
                $query->leftJoin('variations','variations.product_id','products.id')
                    ->orderBy('variations.default_sell_price');
                $query->groupBy('products.id');
                $query->select('products.*');
            }
            if($filters['sort_by']=='high-low'){
                $query->leftJoin('variations','variations.product_id','products.id')
                    ->orderBy('variations.default_sell_price','desc');
                $query->groupBy('products.id');
                $query->select('products.*');
            }
        }

        if (!empty($filters['selling_price_group']) && $filters['selling_price_group'] == true) {
            $with[] = 'product_variations.variations.group_prices';
        }

        // if (!empty($filters['location_id'])) {
            $storefront = Storefront::where('business_id',$business_id)->first();
            if($storefront){
                $query->whereHas('product_locations', function($q) use($storefront) {
                    $q->where('product_locations.location_id', $storefront->location_id);
                });
            }
        // }

        if (!empty($filters['product_ids'])) {
            $query->where('id', $filters['product_ids']);
        }

        if (!empty($filters['product'])) {
            $query->where('id', '!=', $filters['product']);
        }

        $perPage = !empty($filters['per_page']) ? $filters['per_page'] : $this->perPage;

        if (!empty($search)) {
            $query->where(function ($query) use ($search) {

                if (!empty($search['name'])) {
                    $query->where('products.name', 'like', '%' . $search['name'] .'%');
                }
                
                if (!empty($search['sku'])) {
                    $sku = $search['sku'];
                    $query->orWhere('sku', 'like', '%' . $sku .'%');
                    $query->orWhereHas('variations', function($q) use($sku) {
                        $q->where('variations.sub_sku', 'like', '%' . $sku .'%');
                    });
                }
            });
        }

        $query->with($with);

        if ($pagination && $perPage != -1) {
            $products = $query->paginate($perPage);
            $products->appends(request()->query());
        } else{
            $products = $query->get();
        }

        return $products;
    }

    public function listVariations($variation_ids = null)
    {
        $query = Variation::join('products AS p', 'variations.product_id', '=', 'p.id')
                ->join('product_variations AS pv', 'variations.product_variation_id', '=', 'pv.id')
                ->leftjoin('units', 'p.unit_id', '=', 'units.id')
                ->leftjoin('tax_rates as tr', 'p.tax', '=', 'tr.id')
                ->leftjoin('brands', function ($join) {
                    $join->on('p.brand_id', '=', 'brands.id')
                        ->whereNull('brands.deleted_at');
                })
                ->leftjoin('categories as c', 'p.category_id', '=', 'c.id')
                ->leftjoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
                ->where('p.business_id', $this->business_id)
                ->select(
                    'variations.id',
                    'variations.name as variation_name',
                    'variations.sub_sku',
                    'p.id as product_id',
                    'p.name as product_name',
                    'p.sku',
                    'p.type as type',
                    'p.business_id', 
                    'p.barcode_type',
                    'p.expiry_period',
                    'p.expiry_period_type',
                    'p.enable_sr_no',
                    'p.weight',
                    'p.product_custom_field1',
                    'p.product_custom_field2',
                    'p.product_custom_field3',
                    'p.product_custom_field4',
                    'p.image as product_image',
                    'p.product_description',
                    'p.warranty_id',
                    'p.brand_id',
                    'brands.name as brand_name',
                    'p.unit_id',
                    'p.enable_stock',
                    'p.not_for_selling',
                    'units.short_name as unit_name',
                    'units.allow_decimal as unit_allow_decimal',
                    'p.category_id',
                    'c.name as category',
                    'p.sub_category_id',
                    'sc.name as sub_category',
                    'p.tax as tax_id',
                    'p.tax_type',
                    'tr.name as tax_name',
                    'tr.amount as tax_amount',
                    'variations.product_variation_id',
                    'variations.default_purchase_price',
                    'variations.dpp_inc_tax',
                    'variations.profit_percent',
                    'variations.default_sell_price',
                    'variations.sell_price_inc_tax',
                    'pv.id as product_variation_id',
                    'pv.name as product_variation_name'
                )
                ->with([
                    'variation_location_details', 
                    'media', 
                    'group_prices',
                    'product',
                    'product.product_locations'
                ]);

        if (!empty(request()->input('category_id'))) {
            $query->where('category_id', request()->input('category_id'));
        }

        if (!empty(request()->input('sub_category_id'))) {
            $query->where('p.sub_category_id', request()->input('sub_category_id'));
        }

        if (!empty(request()->input('brand_id'))) {
            $query->where('p.brand_id', request()->input('brand_id'));
        }

        if (request()->has('not_for_selling')) {
            $not_for_selling = request()->input('not_for_selling') == 1 ? 1 : 0;
            $query->where('p.not_for_selling', $not_for_selling);
        }
        $filters['selling_price_group'] = request()->input('selling_price_group') == 1 ? true : false;

        $search = request()->only(['sku', 'name']);

        if (!empty($search)) {
            $query->where(function ($query) use ($search) {

                if (!empty($search['name'])) {
                    $query->where('p.name', 'like', '%' . $search['name'] .'%');
                }
                
                if (!empty($search['sku'])) {
                    $sku = $search['sku'];
                    $query->orWhere('p.sku', 'like', '%' . $sku .'%')
                        ->where('variations.sub_sku', 'like', '%' . $sku .'%');
                }
            });
        }

        $perPage = !empty(request()->input('per_page')) ? request()->input('per_page') : $this->perPage;

        if (empty($variation_ids)) {
            if ($perPage == -1) {
                $variations = $query->get();
            } else {
                $variations = $query->paginate($perPage);
                $variations->appends(request()->query());
            }
        } else {
            $variation_ids = explode(',', $variation_ids);
            $variations = $query->whereIn('variations.id', $variation_ids)
                                ->get();
        }

        return VariationResource::collection($variations);
    }

    public function getSellingPriceGroup()
    {
        $price_groups = SellingPriceGroup::where('business_id', $this->business_id)
                                        ->get();

        return CommonResource::collection($price_groups);
    }
}
