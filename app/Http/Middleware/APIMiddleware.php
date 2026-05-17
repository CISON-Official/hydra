<?php

namespace App\Http\Middleware;

use App\Services\AdminService;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class APIMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasHeader("X-API-KEY")) {

            $api_key = $request->header("X-API-KEY");
            Log::info("You just passed your api key: " . $api_key);
            $audit = new AuditService();
            $adminservice = new AdminService($audit);
            $adminservice->hashApiKey($api_key);
            $user = User::where('api_key_hash', $adminservice->hashApiKey($api_key))->first();
            if ($user) {
                $request->setUserResolver(fn() => $user);
                $request->attributes->set('authenticated_user', $user);
                Log::info($user->name . $user->email . $user->role);
                return $next($request);
            }

        }
        return $next($request);
    }
}
