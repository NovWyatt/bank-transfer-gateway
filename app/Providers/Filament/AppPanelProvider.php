<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetCurrentShopFromPanel;
use App\Models\Shop;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Dashboard của shop ở /app/{shop}. Mỗi shop là một tenant của Filament.
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->brandName('Bank Transfer Gateway')
            ->tenant(Shop::class, slugAttribute: 'slug')
            ->tenantMiddleware([SetCurrentShopFromPanel::class], isPersistent: true)
            // Cùng phông tự host với trang /pay; Filament mặc định dùng Inter, skill cấm.
            ->font('Hanken Grotesk', url: asset('css/fonts.css'), provider: LocalFontProvider::class)
            // Đơn sắc: nút chính màu mực, xám trung tính, màu chỉ dùng cho nghĩa (đã nhận, cảnh báo, lỗi).
            ->colors([
                'primary' => Color::hex('#232323'),
                'gray' => Color::Neutral,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Red,
                'info' => Color::Sky,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => new HtmlString('<link rel="stylesheet" href="'.e(asset('css/panel.css')).'?v='.filemtime(public_path('css/panel.css')).'">'),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
