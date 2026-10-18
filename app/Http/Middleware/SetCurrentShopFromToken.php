<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Tenancy\CurrentShop;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sau auth:sanctum: token thuộc về shop nào thì mọi truy vấn model của shop lọc theo shop đó.
 */
final class SetCurrentShopFromToken
{
    public function __construct(
        private readonly CurrentShop $currentShop,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $shop = $request->user('sanctum');

        // Token của model khác (nếu sau này có) không được gọi API của shop.
        if (! $shop instanceof Shop) {
            return new JsonResponse(['message' => 'Token không thuộc shop nào.', 'code' => 'unauthenticated'], 401);
        }

        $this->currentShop->set($shop);

        return $next($request);
    }
}
