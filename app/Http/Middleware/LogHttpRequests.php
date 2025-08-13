<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogHttpRequests
{
    public function handle(Request $request, Closure $next)
    {
        Log::info('[API TRACE]', [
            'method' => $request->getMethod(),
            'uri' => $request->getPathInfo(),
            'ip' => $request->ip(),
            'input' => $request->all(),
        ]);

        return $next($request);
    }
}
