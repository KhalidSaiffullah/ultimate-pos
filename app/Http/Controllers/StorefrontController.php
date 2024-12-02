<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Business;
use App\BusinessLocation;
use App\Storefront;

class StorefrontController extends Controller
{
    public function index()
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $business = Business::with('storefront')->where('id', $business_id)->first();

        if(!$business->storefront){
            $storefront = new Storefront;
            $storefront->business_id = $business->id;
            $storefront->location_id = $business->locations()->first()->id;
            $storefront->created_at = now();
            $storefront->save();
        }

        $locations = BusinessLocation::where('business_locations.business_id', $business_id)->get();

        return view('storefront.index', compact('business','locations'));
    }

    public function update(Request $request)
    {
        if (!auth()->user()->can('business_settings.access')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $storefront = Storefront::where('business_id',$business_id)->first();

            $storefront->location_id = $request->location_id;
            $storefront->save();
            
            $output = [
                'success' => 1,
                'msg' => __('business.settings_updated_success')
            ];
        } catch (\Exception $e) {
            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return redirect('/storefront/settings')->with('status', $output);
    }

    public function homepage()
    {
        $business_id = request()->session()->get('user.business_id');
        $storefront = Storefront::where('business_id',$business_id)->first();

        return view('storefront.homepage',compact('storefront'));
    }

    public function homepageUpdate(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');
        $storefront = Storefront::where('business_id',$business_id)->first();
        if($request->has('banners')){
            $request->validate([
                'banners.*' => 'mimes:jpeg,png,jpg,gif,svg|max:2048',
            ]);
            $images = $this->uploadImages($request->banners);

            if($storefront->banners){
                $images = array_merge(json_decode($storefront->banners),$images);
            }
            $storefront->banners = json_encode($images);
            $storefront->save();
        }
        if($request->has('promotions')){
            $request->validate([
                'promotions.*' => 'mimes:jpeg,png,jpg,gif,svg|max:2048',
            ]);
            $images = $this->uploadImages($request->promotions);

            if($storefront->promotions){
                $images = array_merge(json_decode($storefront->promotions),$images);
            }
            $storefront->promotions = json_encode($images);
            $storefront->save();
        }

        $output = [
            'success' => 1,
            'msg' => __('business.settings_updated_success')
        ];

        return redirect('/storefront/homepage')->with('status', $output);
    }

    public static function uploadImages($images)
    {
        foreach($images as $image){
            $imageName = '/uploads/storefront/'.time().'-'.rand(10,100).'.'.$image->extension();
            $image->move(public_path().'/uploads/storefront', $imageName);
            $uploads[] = [
                'src' => $imageName,
                'title' => $imageName,
                'url' => '/'
            ];
        }

        return $uploads;
    }

    public function delete(Request $request)
    {
        $business_id = request()->session()->get('user.business_id');
        $storefront = Storefront::where('business_id',$business_id)->first();

        $output = [
            'success' => 0,
            'msg' => __('messages.something_went_wrong')
        ];

        try{
            if(file_exists(public_path().$request->src)){
                unlink(public_path().$request->src);
                if($request->type=='banners' || $request->type=='promotions'){
                    $images = json_decode($storefront[$request->type]);
                    $key = null;
                    foreach($images as $index => $image){
                        if($image->src==$request->src){
                            $key = $index;
                            break;
                        }
                    }
                    if($key>=0){
                        array_splice($images,$key,1);
                    }
                    $storefront->{$request->type} = json_encode($images);
                    $storefront->save();
                }
    
                $output = [
                    'success' => 1,
                    'msg' => 'Deleted successfully'
                ];
            }else{
                $output = [
                    'success' => 0,
                    'msg' => 'File not found'
                ];
            }
        } catch(\Exception $e) {
            $output = [
                'success' => 0,
                'msg' => __('messages.something_went_wrong')
            ];
        }

        return $output;
    }
}
