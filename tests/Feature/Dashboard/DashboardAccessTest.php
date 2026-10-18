<?php

use App\Enums\ShopRole;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;

/*
| Cách ly tenant ở mức HTTP: đi đúng route, middleware và global scope như trình duyệt.
*/

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->staff = User::factory()->create();

    $this->shopA = Shop::factory()->withBankAccount()
        ->withMember($this->owner)
        ->withMember($this->staff, ShopRole::Staff)
        ->create();
    $this->shopB = Shop::factory()->withBankAccount()->create();

    $this->orderA = Order::factory()->for($this->shopA)->create(['code' => 'DHAAAA1111']);
    $this->orderB = Order::factory()->for($this->shopB)->create(['code' => 'DHBBBB2222']);
});

it('bắt khách chưa đăng nhập về trang đăng nhập', function (): void {
    $this->get("/app/{$this->shopA->slug}/orders")->assertRedirect('/app/login');
});

it('chỉ hiện đơn của shop đang mở', function (): void {
    $this->actingAs($this->owner)
        ->get("/app/{$this->shopA->slug}/orders")
        ->assertOk()
        ->assertSee('DHAAAA1111')
        ->assertDontSee('DHBBBB2222');
});

it('trả 404 khi mở shop mình không phải thành viên', function (): void {
    $this->actingAs($this->owner)
        ->get("/app/{$this->shopB->slug}/orders")
        ->assertNotFound();
});

it('trả 404 khi mở đơn của shop khác qua URL của shop mình', function (): void {
    $this->actingAs($this->owner)
        ->get("/app/{$this->shopA->slug}/orders/{$this->orderB->getKey()}")
        ->assertNotFound();
});

it('cho staff xem đơn và giao dịch', function (string $page): void {
    $this->actingAs($this->staff)
        ->get("/app/{$this->shopA->slug}/{$page}")
        ->assertOk();
})->with(['orders', 'transactions']);

it('chặn staff ở trang chỉ dành cho owner', function (string $page): void {
    $this->actingAs($this->staff)
        ->get("/app/{$this->shopA->slug}/{$page}")
        ->assertForbidden();
})->with(['bank-accounts', 'provider-connections']);

it('cho owner vào trang tài khoản và kết nối', function (string $page): void {
    $this->actingAs($this->owner)
        ->get("/app/{$this->shopA->slug}/{$page}")
        ->assertOk();
})->with(['bank-accounts', 'provider-connections']);

it('mở được trang tổng quan có số liệu', function (): void {
    $this->actingAs($this->owner)
        ->get("/app/{$this->shopA->slug}")
        ->assertOk();
});
