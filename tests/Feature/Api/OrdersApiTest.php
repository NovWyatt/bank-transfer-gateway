<?php

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Transaction;

beforeEach(function (): void {
    $this->shop = Shop::factory()->withBankAccount()->create();
    $this->account = $this->shop->bankAccounts()->firstOrFail();
});

describe('xác thực và quyền', function (): void {
    it('trả 401 dạng JSON khi thiếu token hoặc token sai', function (): void {
        $this->getJson('/api/v1/orders')->assertUnauthorized()->assertJson(['code' => 'unauthenticated']);

        $this->withToken('btg_khong-ton-tai')->getJson('/api/v1/orders')->assertUnauthorized();
    });

    it('trả 403 khi token thiếu quyền', function (): void {
        apiAs($this->shop, ['orders:read']);

        $this->postJson('/api/v1/orders', ['amount' => 50_000])
            ->assertForbidden()
            ->assertJson(['code' => 'missing_ability']);
    });

    it('token mới có tiền tố btg_ để công cụ quét bí mật nhận ra', function (): void {
        // Sanctum ghép "{id}|" trước phần bí mật.
        expect(apiAs($this->shop))->toMatch('/^\d+\|btg_[A-Za-z0-9]{40}[a-f0-9]{8}$/');
    });

    it('trả 404 JSON cho đường dẫn không có', function (): void {
        apiAs($this->shop);

        $this->getJson('/api/v1/khong-co')->assertNotFound()->assertJson(['code' => 'not_found']);
    });
});

describe('tạo đơn', function (): void {
    it('tạo đơn và trả đủ thông tin để hiện QR hoặc gửi link cho khách', function (): void {
        apiAs($this->shop);

        $response = $this->postJson('/api/v1/orders', [
            'amount' => 150_000,
            'external_ref' => 'WOO-1234',
            'description' => 'Bình gốm men lam',
            'expires_in' => 60,
        ])->assertCreated();

        $order = Order::query()->where('shop_id', $this->shop->getKey())->sole();

        $response->assertJson([
            'data' => [
                'code' => $order->code,
                'status' => 'pending',
                'amount' => 150_000,
                'paid_amount' => 0,
                'remaining_amount' => 150_000,
                'external_ref' => 'WOO-1234',
                'pay_url' => route('pay.show', $order->code),
                'qr_svg_url' => route('pay.qr', $order->code),
                'transfer' => [
                    'account_number' => $this->account->account_number,
                    'amount' => 150_000,
                    'memo' => $order->code,
                ],
            ],
        ]);

        expect($response->json('data.qr_string'))->toStartWith('000201')
            ->and($response->json('data.expires_at'))->toEndWith('Z')
            ->and($order->created_via)->toBe(OrderSource::Api);
    });

    it('báo lỗi kiểm tra dữ liệu bằng tiếng Việt', function (): void {
        apiAs($this->shop);

        $this->postJson('/api/v1/orders', ['amount' => -5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->postJson('/api/v1/orders', [])
            ->assertJsonPath('errors.amount.0', 'Cần điền amount.');
    });

    it('external_ref chỉ cần duy nhất trong shop', function (): void {
        $other = Shop::factory()->withBankAccount()->create();
        Order::factory()->for($other)->create(['external_ref' => 'WOO-1']);

        apiAs($this->shop);
        $this->postJson('/api/v1/orders', ['amount' => 10_000, 'external_ref' => 'WOO-1'])->assertCreated();
        $this->postJson('/api/v1/orders', ['amount' => 10_000, 'external_ref' => 'WOO-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['external_ref']);
    });

    it('báo rõ khi shop chưa có tài khoản ngân hàng mặc định', function (): void {
        $this->account->update(['is_default' => false]);
        apiAs($this->shop);

        $this->postJson('/api/v1/orders', ['amount' => 10_000])
            ->assertUnprocessable()
            ->assertJson(['code' => 'no_bank_account']);
    });
});

describe('đọc đơn', function (): void {
    it('xem được đơn của shop mình, không thấy đơn shop khác', function (): void {
        $mine = Order::factory()->for($this->shop)->create(['bank_account_id' => $this->account->getKey()]);
        $foreign = Order::factory()->create();

        apiAs($this->shop);

        $this->getJson('/api/v1/orders/'.strtolower($mine->code))
            ->assertOk()
            ->assertJsonPath('data.code', $mine->code);

        $this->getJson("/api/v1/orders/{$foreign->code}")
            ->assertNotFound()
            ->assertJson(['code' => 'order_not_found']);
    });

    it('lọc theo trạng thái và phân trang theo cursor', function (): void {
        Order::factory()->for($this->shop)->count(3)->create([
            'bank_account_id' => $this->account->getKey(),
            'status' => OrderStatus::Paid,
        ]);
        Order::factory()->for($this->shop)->create(['bank_account_id' => $this->account->getKey()]);
        Order::factory()->create(['status' => OrderStatus::Paid]);

        apiAs($this->shop);

        $first = $this->getJson('/api/v1/orders?status=paid&per_page=2')->assertOk();

        expect($first->json('data'))->toHaveCount(2)
            ->and(collect($first->json('data'))->pluck('status')->unique()->all())->toBe(['paid'])
            ->and($first->json('meta.next_cursor'))->not->toBeNull();

        $second = $this->getJson('/api/v1/orders?status=paid&per_page=2&cursor='.$first->json('meta.next_cursor'))->assertOk();

        expect($second->json('data'))->toHaveCount(1)
            ->and($second->json('meta.next_cursor'))->toBeNull();
    });
});

describe('hủy đơn', function (): void {
    it('hủy đơn còn nhận tiền, hủy lần hai thì báo 409', function (): void {
        $order = Order::factory()->for($this->shop)->create(['bank_account_id' => $this->account->getKey()]);
        apiAs($this->shop);

        $this->postJson("/api/v1/orders/{$order->code}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.qr_string', null);

        $this->postJson("/api/v1/orders/{$order->code}/cancel")
            ->assertConflict()
            ->assertJson(['code' => 'order_not_cancellable']);

        $log = AuditLog::withoutGlobalScopes()->sole();
        expect($log->user_id)->toBeNull()
            ->and($log->meta['via'])->toBe('api');
    });
});

describe('giao dịch', function (): void {
    it('chỉ trả giao dịch của shop mình, lọc được theo mã đơn', function (): void {
        $order = Order::factory()->for($this->shop)->create(['bank_account_id' => $this->account->getKey()]);
        $matched = Transaction::factory()->for($this->shop)->create([
            'status' => TransactionStatus::Matched,
            'order_id' => $order->getKey(),
        ]);
        Transaction::factory()->for($this->shop)->create();
        Transaction::factory()->create();

        apiAs($this->shop, ['transactions:read']);

        $this->getJson('/api/v1/transactions')->assertOk()->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/transactions?order_code={$order->code}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matched->getKey())
            ->assertJsonPath('data.0.order_code', $order->code);
    });
});

it('mở được tài liệu API do Scramble sinh', function (): void {
    $this->get('/docs/api.json')
        ->assertOk()
        ->assertJsonPath('info.version', '1.0.0')
        ->assertJsonStructure(['paths' => ['/orders', '/orders/{code}', '/orders/{code}/cancel', '/transactions']]);
});
