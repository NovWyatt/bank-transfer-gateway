<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSpikeToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // Token đi trong header: log truy cập của Wasmer ghi nguyên URL, kể cả query string.
        $expected = (string) config('spike.token');
        $given = (string) $request->header('X-Spike-Token', '');

        abort_unless(config('spike.enabled') && $expected !== '' && hash_equals($expected, $given), 404);

        return $next($request);
    }
}
