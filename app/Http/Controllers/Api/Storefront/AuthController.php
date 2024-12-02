<?php

namespace App\Http\Controllers\Api\Storefront;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Transformers\CommonResource;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use App\Utils\ContactUtil;
use Auth;

use App\Contact;
use App\Business;

class AuthController extends ApiController
{
    protected $contactUtil;

    public function __construct(
        ContactUtil $contactUtil
    ) {
        $this->contactUtil = $contactUtil;
        parent::__construct();
    }

    public function register(Request $request)
    {
        $business = Business::find($this->business_id);

        $contactExist = Contact::where('business_id',$business->id)->where('mobile',$request['phone'])->first();

        if($contactExist){
            if($contactExist->api_token==null){
                $contactExist->first_name = $request['firstName'];
                $contactExist->last_name = $request['lastName'];
                if($request['email']!=null){
                    $contactExist->email = $request['email'];
                }
                $contactExist->name = implode(' ', [$request['firstName'], $request['lastName']]);
                $contactExist->password = Hash::make($request['password']);
                $contactExist->api_token = Str::random(60);
                $contactExist->save();

                $output = [
                    'success' => true,
                    'data' => $contactExist,
                    'msg' => "Registered Successfully"
                ];
            }else{
                $output = [
                    'success' => false,
                    'msg' => "Phone number already exists. Please login"
                ];
            }
        }else{
            $contactInput['type'] = 'customer';
            $contactInput['contact_id'] = null;
            $contactInput['first_name'] = $request['firstName'];
            $contactInput['last_name'] = $request['lastName'];
            $contactInput['mobile'] = $request['phone'];
            $contactInput['email'] = $request['email'];
            $contactInput['name'] = implode(' ', [$contactInput['first_name'], $contactInput['last_name']]);
            $contactInput['password'] = Hash::make($request['password']);
            $contactInput['api_token'] = Str::random(60);
            $contactInput['business_id'] = $business->id;
            $contactInput['created_by'] = $business->owner_id;

            $output = $this->contactUtil->createNewContact($contactInput);
        }
        
        return $output;
    }

    public function login(Request $request)
    {
        $business = Business::find($this->business_id);

        $contactExist = Contact::where('business_id',$business->id)
            ->where(function($q) use ($request) {
                $q->where('mobile',$request['email']);
                $q->orWhere('email',$request['email']);
            })
            // ->select('id','name','first_name','last_name','email','mobile','api_token')
            ->first();

        if($contactExist){
            if($contactExist->api_token==null){
                $output = [
                    'success' => false,
                    'msg' => "Email/Mobile doesn't exists. Please register"
                ];
            }else{
                $credentials['email'] = $contactExist['email'];
                $credentials['mobile'] = $contactExist['mobile'];
                $credentials['password'] = $request['password'];
                if (Auth::guard('customer')->attempt($credentials)){
                    $contactExist->api_token = Str::random(60);
                    $contactExist->save();
                    $output = [
                        'success' => true,
                        'data' => $contactExist,
                        'msg' => "Logged in successfully"
                    ];
                }else{
                    $output = [
                        'success' => false,
                        'msg' => "Password didn't match. Please try again"
                    ];
                } 
            }           
        }else{
            $output = [
                'success' => false,
                'msg' => "Email/Mobile doesn't exists. Please register"
            ];
        }
        
        return $output;
    }

    public function customer()
    {
        $business_id = $this->business_id;

        if(request()->header('Authorization')){
            $contact = Contact::where('api_token',request()->header('Authorization'))
                ->where('business_id',$business_id)
                ->with('addressesWithZoneAndCity')
                ->first();
                
            if($contact){
                return [
                    'success' => true,
                    'data' => $contact
                ];
            }

            return [
                'success' => false,
                'msg' => 'Session timed out. Please login again'
            ];
        }else{
            return [
                'success' => false,
                'msg' => 'Session timed out. Please login again'
            ];
        }
    }

    public function update(Request $request)
    {
        $business_id = $this->business_id;

        if(request()->header('Authorization')){
            $contact = Contact::where('api_token',request()->header('Authorization'))
                ->where('business_id',$business_id)
                ->with('addressesWithZoneAndCity')
                ->first();

            if($contact){
                $contact->first_name = $request['firstName'];
                $contact->last_name = $request['lastName'];
                $contact->name = implode(' ', [$request['first_name'], $request['last_name']]);
                $contact->mobile = $request['phone'];
                $contact->email = $request['email'];
                $contact->save();
                
                return [
                    'success' => true,
                    'data' => $contact
                ];
            }else{
                return [
                    'success' => false,
                    'msg' => 'Session timed out. Please login again'
                ];
            }
        }else{
            return [
                'success' => false,
                'msg' => 'Session timed out. Please login again'
            ];
        }
    }

    public function password(Request $request)
    {
        $business_id = $this->business_id;

        if(request()->header('Authorization')){
            $contact = Contact::where('api_token',request()->header('Authorization'))
                ->where('business_id',$business_id)
                ->first();
            
            if($contact){
                if(Hash::check($request->oldPassword, $contact->password)){
                    $contact->password = Hash::make($request->newPassword);
                    $contact->save();

                    return [
                        'success' => true,
                        'msg' => 'Password changed successfully'
                    ];
                }else{
                    return [
                        'success' => false,
                        'msg' => "Old password didn't match"
                    ];
                }
            }else{
                return [
                    'success' => false,
                    'msg' => 'Session timed out. Please login again'
                ];
            }
        }else{
            return [
                'success' => false,
                'msg' => 'Session timed out. Please login again'
            ];
        }
    }
}