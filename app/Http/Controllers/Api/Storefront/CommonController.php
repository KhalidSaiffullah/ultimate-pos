<?php

namespace App\Http\Controllers\Api\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Transformers\CommonResource;

use App\Category;
use App\Brands;
use App\City;
use App\Storefront;

class CommonController extends ApiController
{
    public function index()
    {
        $categories = Category::where('business_id', $this->business_id)
                        ->where('category_type','product')
                        ->onlyParent()
                        ->with('sub_categories')
                        ->get();

        return CommonResource::collection($categories);
    }

    public function homepage()
    {
        $storefront = Storefront::where('business_id', $this->business_id)->first();

        return $storefront; 
    }

    public function brands()
    {
        $query = Brands::join('products','products.brand_id','brands.id')
            ->where('brands.business_id', $this->business_id)
            ->where('brands.deleted_at', null)
            ->groupBy('brands.id')
            ->select('brands.*');
        if(request()->category){
            $category = Category::where('business_id', $this->business_id)->where('slug',request()->category)->first();
            if($category){
                $query->where('products.category_id',$category->id);
            }
        }
        $brands = $query->get();

        return CommonResource::collection($brands);
    }

    public function cities(){
        $cities = City::with('zones')->get()->toArray();

        return $cities;
    }
}