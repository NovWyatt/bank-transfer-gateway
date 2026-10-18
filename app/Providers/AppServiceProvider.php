<?php

namespace App\Providers;

use App\Payments\Banks\BankDirectory;
use App\Tenancy\CurrentShop;
use Illuminate\Database\Eloquent\Model;
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
    }
}
