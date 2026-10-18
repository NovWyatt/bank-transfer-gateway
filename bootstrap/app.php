<?php

use App\Api\ApiError;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\PublicPageHeaders;
use App\Http\Middleware\VerifyInternalSignature;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('throttle:hooks')->group(base_path('routes/hooks.php'));
            // Không throttle: limiter lưu trong bảng cache, mà /internal/deploy phải chạy được khi DB còn trống
            // (lần deploy đầu). Chữ ký HMAC đã loại request rác mà không đụng DB.
            Route::group([], base_path('routes/internal.php'));
            Route::middleware(['throttle:pay', PublicPageHeaders::class])->group(base_path('routes/pay.php'));
            // Nhóm "api": không session, không cookie, không CSRF.
            Route::middleware('api')->prefix('api/v1')->name('api.v1.')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'idempotent' => EnsureIdempotency::class,
            'internal' => VerifyInternalSignature::class,
        ]);

        // Khách chưa đăng nhập vào dashboard thì về trang đăng nhập của panel; API thì trả 401 JSON.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/app/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'hooks/*', 'internal/*', 'telegram/*') || $request->expectsJson(),
        );

        // API trả lỗi cùng một dạng {"message", "code"} để phía shop xử lý theo "code".
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? ApiError::json(401, 'unauthenticated', 'Thiếu token hoặc token không hợp lệ. Gửi header Authorization: Bearer <token>.')
            : null);

        // Handler đổi AuthorizationException (cha của MissingAbilityException) thành AccessDeniedHttpException
        // trước khi gọi callback, nên phải bắt kiểu đã đổi rồi xem ngoại lệ gốc.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            $missing = $e->getPrevious();

            return $request->is('api/*') && $missing instanceof MissingAbilityException
                ? ApiError::json(403, 'missing_ability', 'Token thiếu quyền: '.implode(', ', $missing->abilities()).'.')
                : null;
        });

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? ApiError::json(404, 'not_found', 'Không có đường dẫn này trong API v1.')
            : null);
    })->create();
