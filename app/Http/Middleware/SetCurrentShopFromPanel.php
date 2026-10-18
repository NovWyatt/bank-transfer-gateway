<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Tenancy\CurrentShop;
use Closure;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Đưa shop đang mở trong dashboard (tenant của Filament) vào CurrentShop,
 * để global scope của mọi model thuộc shop lọc theo shop đó.
 * Đăng ký "persistent" nên chạy cả ở các request Livewire.
 */
final class SetCurrentShopFromPanel
{
    public function __construct(
        private readonly CurrentShop $currentShop,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();
        $shop = $tenant instanceof Shop ? $tenant : null;

        $this->currentShop->set($shop);

        // Giờ lưu ở UTC; bảng và trang xem hiện theo múi giờ của shop.
        if ($shop !== null) {
            FilamentTimezone::set($shop->timezone);
        }

        return $next($request);
    }
}
