<?php

use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Shop;

beforeEach(function (): void {
    $this->shop = Shop::factory()->withBankAccount()->create();
    apiAs($this->shop);
});

it('gửi lại cùng khóa, cùng body thì nhận lại đúng phản hồi cũ và không tạo thêm đơn', function (): void {
    $first = $this->postJson('/api/v1/orders', ['amount' => 120_000], ['Idempotency-Key' => 'woo-1234-try'])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    $second = $this->postJson('/api/v1/orders', ['amount' => 120_000], ['Idempotency-Key' => 'woo-1234-try'])
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(Order::query()->count())->toBe(1);
});

it('cùng khóa mà khác body thì báo lỗi', function (): void {
    $this->postJson('/api/v1/orders', ['amount' => 120_000], ['Idempotency-Key' => 'k-1'])->assertCreated();

    $this->postJson('/api/v1/orders', ['amount' => 999_000], ['Idempotency-Key' => 'k-1'])
        ->assertUnprocessable()
        ->assertJson(['code' => 'idempotency_key_reused']);

    expect(Order::query()->count())->toBe(1);
});

it('không lưu phản hồi lỗi: sửa body rồi gửi lại cùng khóa được', function (): void {
    $this->postJson('/api/v1/orders', ['amount' => 0], ['Idempotency-Key' => 'k-2'])->assertUnprocessable();

    $this->postJson('/api/v1/orders', ['amount' => 50_000], ['Idempotency-Key' => 'k-2'])->assertCreated();

    expect(IdempotencyKey::query()->sole()->response_status)->toBe(201);
});

it('khóa tính riêng theo shop', function (): void {
    $this->postJson('/api/v1/orders', ['amount' => 50_000], ['Idempotency-Key' => 'chung'])->assertCreated();

    $other = Shop::factory()->withBankAccount()->create();
    apiAs($other);

    $this->postJson('/api/v1/orders', ['amount' => 50_000], ['Idempotency-Key' => 'chung'])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(Order::withoutGlobalScopes()->count())->toBe(2);
});

it('báo 409 khi request đầu với khóa đó chưa xong', function (): void {
    IdempotencyKey::query()->create([
        'shop_id' => $this->shop->getKey(),
        'key' => 'dang-chay',
        'request_hash' => hash('sha256', "POST api/v1/orders\n".json_encode(['amount' => 50_000])),
        'expires_at' => now()->addDay(),
    ]);

    $this->postJson('/api/v1/orders', ['amount' => 50_000], ['Idempotency-Key' => 'dang-chay'])
        ->assertConflict()
        ->assertJson(['code' => 'idempotency_request_in_progress']);
});

it('khóa quá 24 giờ thì coi như khóa mới', function (): void {
    $this->postJson('/api/v1/orders', ['amount' => 50_000], ['Idempotency-Key' => 'cu'])->assertCreated();

    $this->travel(25)->hours();

    $this->postJson('/api/v1/orders', ['amount' => 50_000], ['Idempotency-Key' => 'cu'])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(Order::query()->count())->toBe(2);
});

it('từ chối khóa có ký tự lạ', function (): void {
    $this->postJson('/api/v1/orders', ['amount' => 50_000], ['Idempotency-Key' => 'có dấu cách'])
        ->assertStatus(400)
        ->assertJson(['code' => 'idempotency_key_invalid']);
});
