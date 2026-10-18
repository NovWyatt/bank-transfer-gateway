<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSpikeToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('spike.token');
        $given = (string) $request->query('token', '');

        abort_unless(config('spike.enabled') && $expected !== '' && hash_equals($expected, $given), 404);

        return $next($request);
    }
}
