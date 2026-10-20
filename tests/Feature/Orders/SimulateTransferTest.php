<?php

use App\Enums\OrderStatus;
use App\Enums\Provider;
use App\Enums\ShopRole;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\InboundEvent;
use App\Models\Order;
use App\Models\ProviderConnection;
use App\Models\Shop;
use App\Models\Transaction;
use App\Models\User;
use App\Tenancy\CurrentShop;
use App\Tenancy\ShopScope;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| Giả lập khách chuyển khoản: nút trên trang đơn (chỉ owner) và lệnh pay:simulate.
| Đi qua IngestWebhook trong tiến trình, không có request HTTP nào ra ngoài.
*/

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->owner = User::factory()->create();
    $this->staff = User::factory()->create();
    $this->shop = Shop::factory()->withBankAccount()
        ->withMember($this->owner)
        ->withMember($this->staff, ShopRole::Staff)
        ->create();
    $this->order = Order::factory()->for($this->shop)->create([
        'bank_account_id' => $this->shop->bankAccounts()->firstOrFail()->getKey(),
        'amount' => 150_000,
    ]);

    $this->actingAs($this->owner);
    Filament::setCurrentPanel('app');
    Filament::setTenant($this->shop);
    app(CurrentShop::class)->set($this->shop);
});

it('owner giả lập đủ tiền thì đơn sang đã thanh toán, qua đúng đường nhận có kiểm chữ ký', function (): void {
    Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
        ->assertActionVisible('simulateTransfer')
        ->callAction('simulateTransfer')
        ->assertNotified();

    $connection = ProviderConnection::withoutGlobalScope(ShopScope::class)->sole();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($connection->provider)->toBe(Provider::Simulator)
        ->and($connection->shop_id)->toBe($this->shop->getKey())
        ->and(Transaction::withoutGlobalScope(ShopScope::class)->sole()->provider)->toBe(Provider::Simulator)
        ->and(InboundEvent::withoutGlobalScope(ShopScope::class)->sole()->signature_valid)->toBeTrue();
});

it('giả lập thiếu tiền rồi dùng lại kết nối giả lập đã có', function (): void {
    Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
        ->callAction('simulateTransfer', ['amount' => 50_000]);

    expect($this->order->fresh()->status)->toBe(OrderStatus::PartiallyPaid);

    $this->artisan('pay:simulate', ['code' => $this->order->code])->assertSuccessful();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(ProviderConnection::withoutGlobalScope(ShopScope::class)->count())->toBe(1)
        ->and(Transaction::withoutGlobalScope(ShopScope::class)->count())->toBe(2);
});

it('nhân viên không thấy nút, và tắt giả lập thì cả owner cũng không thấy', function (): void {
    $this->actingAs($this->staff);
    Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
        ->assertActionHidden('simulateTransfer');

    $this->actingAs($this->owner);
    config(['payments.simulator_enabled' => false]);
    Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
        ->assertActionHidden('simulateTransfer');

    $this->artisan('pay:simulate', ['code' => $this->order->code])->assertFailed();
    expect($this->order->fresh()->status)->toBe(OrderStatus::Pending);
});
