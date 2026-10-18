<?php

namespace App\Providers;

use App\Payments\Banks\BankDirectory;
use App\Tenancy\CurrentShop;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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

        // Link mời chỉ mở vài lần; giới hạn chặt để dò token không có ý nghĩa.
        RateLimiter::for('invitations', fn (Request $request) => Limit::perMinute(30)->by('invitations:'.$request->ip()));
    }
}
