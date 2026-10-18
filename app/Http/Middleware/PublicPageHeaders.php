<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header bảo mật cho trang công khai: chỉ tải tài nguyên của chính site, không cho nhúng vào khung,
 * không gửi URL (chứa mã đơn) sang trang khác.
 */
final class PublicPageHeaders
{
    private const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
        ."font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', self::CSP);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
