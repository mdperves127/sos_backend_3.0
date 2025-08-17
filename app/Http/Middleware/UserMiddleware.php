<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserMiddleware {
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle( Request $request, Closure $next ) {
        if ( Auth::check() ) {
            if ( auth()->user()->role_as == '4' ) {
                if ( auth()->user()->status == 'active' && auth()->user()->email_verified_at != null ) {
                    return $next( $request );
                } elseif (auth()->user()->status == 'blocked'){
                    return response()->json( [
                        'status'  => 403,
                        'message' => 'Account is blocked. please contect with admin !',
                    ] );
                }else {
                    return response()->json( [
                        'status'  => 403,
                        'message' => 'Account is not active',
                    ] );
                }
            } else {
                return response()->json( [
                    'status'  => 403,
                    'message' => 'Access Denied.! As you are not an User.',
                ] );
            }
        } else {
            return response()->json( [
                'status'  => 401,
                'message' => 'Please Login First.',
            ] );
        }
    }
}
