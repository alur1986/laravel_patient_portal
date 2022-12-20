<?php

namespace App\Http\Middleware;

use App\Account;
use App\AccountPrefrence;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class Authenticate
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $guard = null)
    {
        $hostarray = explode('.', $_SERVER['HTTP_HOST']);
        $subdomain = $hostarray[0];
        $account = Account::where('pportal_subdomain', $subdomain)->first();

        if (empty($account)) {
            return view('errors.404');
        }
        $account_preference = AccountPrefrence::where('account_id', $account->id)->first();
        $request->session()->put('is_membership_enable', $account_preference->is_membership_enable);

        if (Auth::guard($guard)->guest()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response('Unauthorized.', 401);
            } else {
                return redirect()->guest('login');
            }
        }

        return $next($request);
    }
}
