<?php

use App\Enums\ShopRole;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Filament\Pages\ApiTokens;
use App\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Filament\Resources\WebhookEndpoints\Pages\ViewWebhookEndpoint;
use App\Filament\Resources\WebhookEndpoints\RelationManagers\DeliveriesRelationManager;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\CurrentShop;
use App\Webhooks\Security\HostResolver;
use App\Webhooks\WebhookDispatcher;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\StaticHostResolver;

beforeEach(function (): void {
    Http::preventStrayRequests();
    app()->instance(HostResolver::class, new StaticHostResolver([
        'shop.example' => ['93.184.216.34'],
        'noi-bo.example' => ['10.0.0.7'],
    ]));

    $this->owner = User::factory()->create();
    $this->staff = User::factory()->create();
    $this->shop = Shop::factory()
        ->withMember($this->owner)
        ->withMember($this->staff, ShopRole::Staff)
        ->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel('app');
    Filament::setTenant($this->shop);
    app(CurrentShop::class)->set($this->shop);
});

/** Bí mật đang hiện trong hộp "chỉ hiện một lần": nằm ở đối số của action đang mở, không phải chữ trong HTML. */
function revealedSecret(Testable $component): string
{
    return (string) ($component->get('mountedActions')[0]['arguments']['secret'] ?? '');
}

it('chỉ owner vào được trang webhook và token API', function (): void {
    $endpoint = WebhookEndpoint::factory()->for($this->shop)->create();

    foreach (['webhook-endpoints', "webhook-endpoints/{$endpoint->getKey()}", 'api-tokens'] as $path) {
        $this->actingAs($this->owner)->get("/app/{$this->shop->slug}/{$path}")->assertOk();
        $this->actingAs($this->staff)->get("/app/{$this->shop->slug}/{$path}")->assertForbidden();
    }
});

it('không mở được endpoint của shop khác', function (): void {
    $foreign = WebhookEndpoint::factory()->create();

    $this->get("/app/{$this->shop->slug}/webhook-endpoints/{$foreign->getKey()}")->assertNotFound();
});

it('thêm endpoint rồi hiện secret đúng một lần', function (): void {
    Livewire::test(ListWebhookEndpoints::class)
        ->callAction('createEndpoint', data: [
            'url' => 'https://shop.example/hooks',
            'events' => [WebhookEventType::OrderPaid->value, WebhookEventType::OrderExpired->value],
        ])
        ->assertHasNoFormErrors()
        ->assertActionMounted('revealSecret')
        ->assertMountedActionModalSee('Secret của endpoint');

    $endpoint = WebhookEndpoint::query()->sole();

    expect($endpoint->shop_id)->toBe($this->shop->getKey())
        ->and($endpoint->secret)->toStartWith('whsec_')
        ->and($endpoint->events)->toBe(['order.paid', 'order.expired'])
        ->and(AuditLog::query()->where('action', 'webhook_endpoint.created')->exists())->toBeTrue();
});

it('từ chối URL không phải https hoặc trỏ vào mạng nội bộ', function (string $url): void {
    Livewire::test(ListWebhookEndpoints::class)
        ->callAction('createEndpoint', data: ['url' => $url, 'events' => ['order.paid']])
        ->assertHasFormErrors(['url']);

    expect(WebhookEndpoint::query()->count())->toBe(0);
})->with([
    'http' => 'http://shop.example/hooks',
    'mạng nội bộ' => 'https://noi-bo.example/hooks',
    'metadata' => 'https://169.254.169.254/latest',
]);

it('gửi thử từ trang endpoint và báo kết quả', function (): void {
    Http::fake(['https://shop.example/*' => Http::response('ok')]);
    $endpoint = WebhookEndpoint::factory()->for($this->shop)->create();

    Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getKey()])
        ->callAction('sendTest')
        ->assertNotified('Gửi thử: endpoint trả HTTP 200 sau '.WebhookDelivery::sole()->attempts()->sole()->duration_ms.' ms.');

    expect(WebhookDelivery::sole()->status)->toBe(WebhookDeliveryStatus::Succeeded);
});

it('đổi secret thì secret cũ hết hiệu lực và secret mới hiện một lần', function (): void {
    $endpoint = WebhookEndpoint::factory()->for($this->shop)->create();
    $old = $endpoint->secret;

    $component = Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getKey()])
        ->callAction('rotateSecret')
        ->assertActionMounted('revealSecret');

    expect($endpoint->fresh()->secret)->not->toBe($old)->toStartWith('whsec_')
        ->and(revealedSecret($component))->toBe($endpoint->fresh()->secret);
});

it('bật lại endpoint đã tự tắt thì xóa chuỗi hỏng', function (): void {
    $endpoint = WebhookEndpoint::factory()->for($this->shop)->create([
        'active' => false,
    ]);
    $endpoint->forceFill(['consecutive_failures' => 25, 'failing_since' => now()->subDays(2), 'disabled_reason' => 'Tự tắt'])->save();

    Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getKey()])
        ->callAction('enable');

    expect($endpoint->fresh())
        ->active->toBeTrue()
        ->consecutive_failures->toBe(0)
        ->disabled_reason->toBeNull();
});

it('nhật ký gửi có nút gửi lại, chạy đồng bộ', function (): void {
    Http::fake(['*' => Http::response('', 204)]);
    $endpoint = WebhookEndpoint::factory()->for($this->shop)->create();
    app(WebhookDispatcher::class)->order(WebhookEventType::OrderPaid, Order::factory()->for($this->shop)->create());
    $delivery = WebhookDelivery::sole();
    $delivery->update(['status' => WebhookDeliveryStatus::Failed, 'next_attempt_at' => null]);

    Livewire::test(DeliveriesRelationManager::class, ['ownerRecord' => $endpoint, 'pageClass' => ViewWebhookEndpoint::class])
        ->assertCanSeeTableRecords([$delivery])
        ->callAction(TestAction::make('resend')->table($delivery))
        ->assertNotified();

    expect($delivery->fresh()->status)->toBe(WebhookDeliveryStatus::Succeeded);
});

it('tạo token API: hiện một lần, đúng quyền đã chọn, gắn với shop', function (): void {
    $component = Livewire::test(ApiTokens::class)
        ->callAction('createToken', data: [
            'name' => 'WooCommerce',
            'abilities' => ['orders:read', 'transactions:read'],
            'expires_in_days' => 90,
        ])
        ->assertHasNoFormErrors()
        ->assertActionMounted('revealSecret');

    $token = PersonalAccessToken::query()->sole();

    // Token hiện trong hộp chính là token dùng được: bản băm khớp với bản đã lưu.
    expect(revealedSecret($component))->toStartWith($token->getKey().'|btg_')
        ->and(PersonalAccessToken::findToken(revealedSecret($component))?->is($token))->toBeTrue();

    expect($token->tokenable_id)->toBe($this->shop->getKey())
        ->and($token->tokenable_type)->toBe($this->shop->getMorphClass())
        ->and($token->abilities)->toBe(['orders:read', 'transactions:read'])
        ->and((int) round(now()->diffInDays($token->expires_at)))->toBe(90);
});

it('chỉ thấy và thu hồi được token của shop mình', function (): void {
    $mine = $this->shop->createToken('Của mình')->accessToken;
    Shop::factory()->create()->createToken('Shop khác');

    Livewire::test(ApiTokens::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCountTableRecords(1)
        ->callAction(TestAction::make('revoke')->table($mine));

    expect(PersonalAccessToken::query()->pluck('name')->all())->toBe(['Shop khác'])
        ->and(AuditLog::query()->where('action', 'api_token.revoked')->exists())->toBeTrue();
});
