<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserFrontendEnabled
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $enabled = filter_var(
            admin_setting('frontend_enable', true),
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$enabled) {
            return response('', 404);
        }

        return $next($request);
    }
}
