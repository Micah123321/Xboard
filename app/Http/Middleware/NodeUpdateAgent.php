<?php

namespace App\Http\Middleware;

use App\Exceptions\NodeUpdateException;
use App\Services\NodeUpdate\NodeUpdateService;
use App\Services\NodeUpdate\Protocol;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class NodeUpdateAgent
{
    public function handle(Request $request, Closure $next)
    {
        try {
            if (!$request->isSecure() || !$request->isJson() || $request->query->count() || strlen($request->getContent())>131072) Protocol::fail();
            $body=json_decode($request->getContent());
            if (!is_object($body) || json_last_error()!==JSON_ERROR_NONE) Protocol::fail();
            $enroll=$request->route()?->getActionMethod()==='enroll';
            $key='node-update:'.hash('sha256',$enroll ? (string)$request->ip() : (string)$request->bearerToken());
            if (RateLimiter::tooManyAttempts($key,$enroll?20:240)) Protocol::fail('rate_limited',429);
            RateLimiter::hit($key,60);
            if (!$enroll) {
                $id=$request->json('installation_id');
                Protocol::uuid($id);
                app(NodeUpdateService::class)->authenticate((string)$request->bearerToken(),$id);
            }
            return $next($request);
        } catch (NodeUpdateException $e) { return $e->render($request); }
    }
}
