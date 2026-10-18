<?php

use App\Enums\OrderStatus;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\Shop;
use App\Payments\Banks\BankDirectory;
use App\Payments\PaymentView;

beforeEach(function () {
    $this->freezeSecond();

    $this->shop = Shop::factory()->create(['name' => 'Tiệm gốm Nhà Mây']);
    $this->account = BankAccount::factory()->default()->for($this->shop)->create([
        'bank_bin' => '970436',
        'bank_code' => 'VCB',
        'account_number' => '1017588888',
        'account_name' => 'NGUYEN VAN A',
    ]);
    $this->order = Order::factory()->for($this->shop)->create([
        'bank_account_id' => $this->account->id,
        'code' => 'DH7K3QXM',
        'amount' => 150_000,
        'expires_at' => now()->addMinutes(30),
    ]);
});

describe('trang /pay', function () {
    it('hiện câu chuyển khoản, thông tin tài khoản và QR', function () {
        $this->get('/pay/DH7K3QXM')
            ->assertOk()
            ->assertSee('Chuyển 150.000đ')
            ->assertSee('DH7K3QXM')
            ->assertSee('1017588888')
            ->assertSee('NGUYEN VAN A')
            ->assertSee('Vietcombank')
            ->assertSee('/qr/DH7K3QXM.svg?a=150000', false)
            ->assertSee('data-state="waiting"', false);
    });

    it('không tạo session hay cookie và có header bảo mật', function () {
        $response = $this->get('/pay/DH7K3QXM')->assertOk();

        expect($response->headers->getCookies())->toBeEmpty()
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    });

    it('nhận mã viết thường', function () {
        $this->get('/pay/dh7k3qxm')->assertOk()->assertSee('DH7K3QXM');
    });

    it('trả 404 khi không có đơn', function () {
        $this->get('/pay/DH2222222')->assertNotFound();
    });

    it('trả thiếu thì mời chuyển nốt phần còn lại, QR theo số còn thiếu', function () {
        $this->order->update(['status' => OrderStatus::PartiallyPaid, 'paid_amount' => 100_000]);

        $this->get('/pay/DH7K3QXM')
            ->assertSee('Còn thiếu 50.000đ')
            ->assertSee('Đã nhận 100.000đ trên 150.000đ')
            ->assertSee('/qr/DH7K3QXM.svg?a=50000', false);
    });

    it('đã trả đủ thì hiện biên nhận, không còn QR', function () {
        $this->order->update(['status' => OrderStatus::Paid, 'paid_amount' => 150_000, 'paid_at' => now()]);

        $this->get('/pay/DH7K3QXM')
            ->assertSee('Đã nhận 150.000đ')
            ->assertSee('data-state="paid"', false)
            ->assertDontSee('/qr/DH7K3QXM.svg', false);
    });

    it('đơn chờ đã quá hạn thì hiện hết hạn dù lệnh hết hạn chưa chạy', function () {
        $this->order->update(['expires_at' => now()->subMinute()]);

        $this->get('/pay/DH7K3QXM')
            ->assertSee('Đơn đã hết hạn')
            ->assertSee('data-state="expired"', false)
            ->assertDontSee('/qr/DH7K3QXM.svg', false);
    });
});

describe('API trạng thái', function () {
    it('trả trạng thái và dấu vết để trang biết khi nào cần cập nhật', function () {
        $this->getJson('/pay/DH7K3QXM/status')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'state' => 'waiting',
                'signature' => 'waiting:0',
                'amount' => 150_000,
                'paid_amount' => 0,
                'remaining' => 150_000,
                'expires_at' => now()->addMinutes(30)->toIso8601ZuluString(),
                'paid_at' => null,
            ]);
    });

    it('đổi dấu vết khi nhận thêm tiền dù trạng thái không đổi', function () {
        $this->order->update(['status' => OrderStatus::PartiallyPaid, 'paid_amount' => 50_000]);
        $first = $this->getJson('/pay/DH7K3QXM/status')->json('signature');

        $this->order->update(['paid_amount' => 100_000]);
        $second = $this->getJson('/pay/DH7K3QXM/status')->json('signature');

        expect($first)->toBe('partial:50000')
            ->and($second)->toBe('partial:100000');
    });
});

describe('ảnh QR', function () {
    it('trả SVG', function () {
        $response = $this->get('/qr/DH7K3QXM.svg')->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('image/svg+xml')
            ->and($response->getContent())->toContain('<svg');
    });

    it('chứa số còn thiếu và mã đơn trong chuỗi VietQR', function () {
        $this->order->update(['status' => OrderStatus::PartiallyPaid, 'paid_amount' => 100_000]);

        $payload = PaymentView::for($this->order->fresh(['bankAccount', 'shop']), app(BankDirectory::class))->qrPayload();

        // Trường 54 là số tiền, trường 62.08 là nội dung chuyển khoản.
        expect($payload)->toContain('540550000')
            ->toContain('0808DH7K3QXM');
    });

    it('không phát QR cho đơn đã trả đủ hoặc đã hủy', function (OrderStatus $status) {
        $this->order->update(['status' => $status]);

        $this->get('/qr/DH7K3QXM.svg')->assertStatus(410);
    })->with([OrderStatus::Paid, OrderStatus::Cancelled, OrderStatus::Expired]);
});
