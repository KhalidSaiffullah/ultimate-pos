<?php

namespace App\Http\Middleware;

use Closure;
use App\Contact;

class CheckStorefrontAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if(request()->header('Authorization')){
            $contact = Contact::where('api_token',request()->header('Authorization'))
                ->where('business_id',request()->header('business_id'))
                ->first();
            if($contact){
                return $next($request);
            }
        }

        // return 'Unauthorized';
        return response()->json([
            'error' => [
                'message' => 'Unauthorized'
            ]
        ], 401);
    }
}
