<?php

namespace App\Http\Middleware;

use Closure;

class CheckBusinessId
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
        if (!$request->header('business_id')) {
            return response()->json([
                'error' => [
                    'message' => 'Invalid Business ID'
                ]
            ], 400);
        }

        return $next($request);
    }
}
