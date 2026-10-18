<?php

use App\Enums\OrderStatus;
use App\Enums\ShopRole;
use App\Internal\InternalSignature;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopInvitation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| /internal/tick (Worker gọi mỗi phút), /internal/deploy (CI gọi sau deploy), /internal/bootstrap (một lần).
*/

beforeEach(function (): void {
    Http::preventStrayRequests();

    config([
        'internal.tick_secret' => 'tick-secret-test',
        'internal.deploy_secret' => 'deploy-secret-test',
    ]);
});

/**
 * POST có ký như Worker và CI: chữ ký tính trên đúng các byte của body.
 *
 * @param  array<string, mixed>|null  $json
 */
function internalPost(string $path, ?string $secret, ?array $json = null, ?int $timestamp = null): TestResponse
{
    $body = $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_X_INTERNAL_SIGNATURE'] = InternalSignature::header($secret, 'POST', $path, $body, $timestamp ?? now()->getTimestamp());
    }

    return test()->call('POST', $path, server: $server, content: $body);
}

it('chạy các việc đến hạn trong lịch khi chữ ký đúng', function (): void {
    $this->travelTo(now()->startOfHour());
    $overdue = Order::factory()->create(['expires_at' => now()->subMinute()]);

    $response = internalPost('/internal/tick', 'tick-secret-test')->assertOk();

    expect($response->json('ran'))->toContain('orders-expire', 'webhooks-deliver', 'prune-old-records')
        ->and($response->json('failed'))->toBe([])
        ->and($overdue->fresh()->status)->toBe(OrderStatus::Expired);
});

it('việc chạy hàng giờ không chạy ở phút khác', function (): void {
    $this->travelTo(now()->startOfHour()->addMinutes(7));

    $ran = internalPost('/internal/tick', 'tick-secret-test')->assertOk()->json('ran');

    expect($ran)->toContain('orders-expire')->not->toContain('prune-old-records');
});

it('từ chối tick không ký, ký sai secret hoặc chữ ký cũ', function (?string $secret, int $age): void {
    internalPost('/internal/tick', $secret, timestamp: now()->getTimestamp() - $age)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'invalid_signature');
})->with([
    'không ký' => [null, 0],
    'sai secret' => ['khong-dung', 0],
    'cũ 10 phút' => ['tick-secret-test', 600],
]);

it('chữ ký của tick không mở được deploy và ngược lại', function (): void {
    internalPost('/internal/deploy', 'tick-secret-test')->assertUnauthorized();
    internalPost('/internal/tick', 'deploy-secret-test')->assertUnauthorized();
});

it('đường nội bộ trả 404 khi chưa đặt secret', function (): void {
    config(['internal.tick_secret' => null, 'internal.deploy_secret' => '']);

    internalPost('/internal/tick', 'tick-secret-test')->assertNotFound();
    internalPost('/internal/deploy', 'deploy-secret-test')->assertNotFound();
});

it('deploy chạy migrate và bỏ qua Telegram khi chưa có token', function (): void {
    config(['services.telegram.bot_token' => null]);

    $response = internalPost('/internal/deploy', 'deploy-secret-test')->assertOk();

    expect($response->json('migrate.exit_code'))->toBe(0)
        ->and($response->json('telegram_webhook'))->toContain('bỏ qua');
    Http::assertNothingSent();
});

it('deploy đăng ký webhook Telegram kèm secret khi đã cấu hình', function (): void {
    config([
        'services.telegram.bot_token' => '123:token-test',
        'services.telegram.webhook_secret' => 'tg-secret-test',
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

    internalPost('/internal/deploy', 'deploy-secret-test')
        ->assertOk()
        ->assertJsonPath('telegram_webhook', 'Đã đăng ký '.route('telegram.webhook'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot123:token-test/setWebhook'
        && $request['url'] === route('telegram.webhook')
        && $request['secret_token'] === 'tg-secret-test'
        && $request['allowed_updates'] === ['message', 'my_chat_member']);
});

it('lỗi từ Telegram lúc deploy không lộ token', function (): void {
    config([
        'services.telegram.bot_token' => '123:token-test',
        'services.telegram.webhook_secret' => 'tg-secret-test',
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);

    $response = internalPost('/internal/deploy', 'deploy-secret-test')->assertOk();

    expect($response->json('telegram_webhook'))->toContain('Unauthorized')
        ->and($response->getContent())->not->toContain('token-test');
});

it('bootstrap tạo shop đầu tiên và lời mời owner, chỉ một lần', function (): void {
    $response = internalPost('/internal/bootstrap', 'deploy-secret-test', [
        'shop_name' => 'Tiệm Bánh Mì Cô Ba',
        'slug' => 'banh-mi-co-ba',
        'owner_email' => 'Chu.Shop@Example.com',
    ])->assertCreated();

    $shop = Shop::sole();
    $invitation = ShopInvitation::query()->withoutGlobalScopes()->sole();

    expect($shop->slug)->toBe('banh-mi-co-ba')
        ->and($invitation->email)->toBe('chu.shop@example.com')
        ->and($invitation->role)->toBe(ShopRole::Owner)
        ->and($invitation->invited_by)->toBeNull()
        ->and($response->json('invitation_url'))->toStartWith(url('/app/invitations/'));

    internalPost('/internal/bootstrap', 'deploy-secret-test', [
        'shop_name' => 'Shop thứ hai',
        'slug' => 'shop-thu-hai',
        'owner_email' => 'khac@example.com',
    ])->assertStatus(409)->assertJsonPath('code', 'already_bootstrapped');

    expect(Shop::count())->toBe(1);
});

it('bootstrap từ chối slug trùng đường của dashboard', function (string $slug): void {
    internalPost('/internal/bootstrap', 'deploy-secret-test', [
        'shop_name' => 'Shop',
        'slug' => $slug,
        'owner_email' => 'chu@example.com',
    ])->assertUnprocessable()->assertJsonValidationErrors('slug');

    expect(Shop::count())->toBe(0);
})->with(['invitations', 'login', 'Co-Hoa', 'co--ba', '-dau']);

it('bootstrap cần secret của deploy, không phải của tick', function (): void {
    internalPost('/internal/bootstrap', 'tick-secret-test', [
        'shop_name' => 'Shop',
        'slug' => 'shop',
        'owner_email' => 'chu@example.com',
    ])->assertUnauthorized();
});
