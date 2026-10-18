<?php

use App\Enums\DeliveryTrigger;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Models\Shop;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Security\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\StaticHostResolver;

beforeEach(function (): void {
    Http::preventStrayRequests();
    app()->instance(HostResolver::class, new StaticHostResolver(['shop.example' => ['93.184.216.34']]));

    $this->shop = Shop::factory()->create();
    $this->endpoint = WebhookEndpoint::factory()->for($this->shop)->create();
});

it('gửi ngay sự kiện webhook.ping có chữ ký và trả kết quả', function (): void {
    Http::fake(['https://shop.example/*' => Http::response('pong', 200)]);
    apiAs($this->shop, ['webhooks:write']);

    $response = $this->postJson("/api/v1/webhook-endpoints/{$this->endpoint->getKey()}/test")
        ->assertOk()
        ->assertJson(['data' => ['delivered' => true, 'response_code' => 200, 'response_body' => 'pong', 'error' => null]]);

    $delivery = WebhookDelivery::sole();

    expect($delivery->event_type)->toBe(WebhookEventType::Ping)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->attempts()->sole()->trigger)->toBe(DeliveryTrigger::Manual)
        ->and($response->json('data.event_id'))->toBe($delivery->event_id);

    Http::assertSent(fn (Request $request): bool => $request->header('X-Gateway-Event-Type')[0] === 'webhook.ping');
});

it('endpoint trả lỗi thì delivered là false, bản ghi kết thúc ở "thất bại" và không gửi lại', function (): void {
    Http::fake(['*' => Http::response('Not Found', 404)]);
    apiAs($this->shop, ['webhooks:write']);

    $this->postJson("/api/v1/webhook-endpoints/{$this->endpoint->getKey()}/test")
        ->assertOk()
        ->assertJsonPath('data.delivered', false)
        ->assertJsonPath('data.response_code', 404);

    expect(WebhookDelivery::sole())
        ->status->toBe(WebhookDeliveryStatus::Failed)
        ->next_attempt_at->toBeNull();
});

it('cần quyền webhooks:write và chỉ thấy endpoint của shop mình', function (): void {
    Http::fake();
    $foreign = WebhookEndpoint::factory()->create();

    apiAs($this->shop, ['orders:read']);
    $this->postJson("/api/v1/webhook-endpoints/{$this->endpoint->getKey()}/test")
        ->assertForbidden()
        ->assertJson(['code' => 'missing_ability']);

    apiAs($this->shop, ['webhooks:write']);
    $this->postJson("/api/v1/webhook-endpoints/{$foreign->getKey()}/test")
        ->assertNotFound()
        ->assertJson(['code' => 'webhook_endpoint_not_found']);

    Http::assertNothingSent();
    expect(WebhookDelivery::count())->toBe(0);
});
