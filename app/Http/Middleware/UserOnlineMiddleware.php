<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserOnlineMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // return $next($request);
        if (Auth::check()) {
            if(Carbon::parse(auth()->user()->last_seen)->diffInMinutes(Carbon::now()) > 30){
                auth()->user()->tokens()->delete();
            }else{
                User::where('id', auth()->id())->update(['last_seen' => now()]);
            }
        }

        return $next($request);
    }
}
