<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use App\Account;
use App\AccountPrefrence;

class MobileAuthenticate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, Closure $next, $guard = null)
    {
        var_dump(Auth::guard()->user());
        if (!Auth::guard()->check()) {
            return response(array('status' => 'unauthorized', 'message' => '', 'data' => strtotime(date('Y-m-d H:i:s'))), 401);
        }

        return $next($request);
    }
}
