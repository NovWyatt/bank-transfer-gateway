<?php

namespace App\Providers;

use App\Models\Shop;
use App\Models\User;
use App\Payments\Banks\BankDirectory;
use App\Tenancy\CurrentShop;
use App\Webhooks\Security\HostResolver;
use App\Webhooks\Security\SystemHostResolver;
use App\Webhooks\Security\UrlGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: tự xóa sau mỗi request và mỗi job, shop không rò sang việc sau.
        $this->app->scoped(CurrentShop::class);

        $this->app->singleton(BankDirectory::class, fn () => new BankDirectory(
            database_path('data/banks.json'),
        ));

        $this->app->bind(HostResolver::class, SystemHostResolver::class);

        // Cho phép địa chỉ nội bộ chỉ khi KHÔNG phải production, dù biến môi trường có bật.
        $this->app->bind(UrlGuard::class, fn ($app) => new UrlGuard(
            $app->make(HostResolver::class),
            allowPrivate: ! $app->isProduction() && (bool) config('webhooks.allow_private_targets'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ngoài production: báo lỗi khi lazy load, gán thuộc tính không khai báo,
        // hoặc đọc cột không có trong kết quả truy vấn.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Mỗi kết nối nhận tối đa 120 webhook/phút; dư sức cho một shop, chặn dội request vào bảng inbound_events.
        RateLimiter::for('hooks', fn (Request $request) => Limit::perMinute(120)->by(
            'hooks:'.($request->route('uuid') ?? $request->ip()),
        ));

        // Trang thanh toán hỏi trạng thái 5 giây một lần (12 request/phút); 120 cho thoải mái
        // nhiều tab, đủ chặn dò mã đơn hàng loạt.
        RateLimiter::for('pay', fn (Request $request) => Limit::perMinute(120)->by('pay:'.$request->ip()));

        // API v1: 120 request/phút cho mỗi shop (đã qua auth:sanctum nên biết shop), còn lại theo IP.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            ($shop = $request->user('sanctum')) instanceof Shop ? 'api:shop:'.$shop->getKey() : 'api:ip:'.$request->ip(),
        ));

        // Gửi thử webhook chạy đồng bộ, có thể mất tới 10 giây: giới hạn chặt hơn API thường.
        RateLimiter::for('webhook-test', fn (Request $request) => Limit::perMinute(10)->by(
            ($shop = $request->user('sanctum')) instanceof Shop ? 'webhook-test:shop:'.$shop->getKey() : 'webhook-test:ip:'.$request->ip(),
        ));

        // Tài liệu API (/docs/api) công khai: chỉ mô tả cấu trúc, không có dữ liệu của shop nào.
        Gate::define('viewApiDocs', fn (?User $user = null): bool => true);

        // Link mời chỉ mở vài lần; giới hạn chặt để dò token không có ý nghĩa.
        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(30)->by('invitations:'.$request->ip()));
    }
}
