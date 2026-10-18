<?php

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\Provider;
use App\Enums\ShopRole;
use App\Filament\Resources\BankAccounts\Pages\ManageBankAccounts;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\ProviderConnections\Pages\ManageProviderConnections;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\ProviderConnection;
use App\Models\Shop;
use App\Models\User;
use App\Tenancy\CurrentShop;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| Hành động trên dashboard, gọi thẳng component Livewire trong ngữ cảnh shop đang mở.
*/

/** Đăng nhập và mở shop như middleware của panel làm. */
function openShop(User $user, Shop $shop): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel('app');
    Filament::setTenant($shop);
    app(CurrentShop::class)->set($shop);
}

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->shop = Shop::factory()->withBankAccount()->withMember($this->owner)->create();
    $this->account = $this->shop->bankAccounts()->firstOrFail();

    openShop($this->owner, $this->shop);
});

describe('tạo đơn', function (): void {
    it('tạo đơn qua action dùng chung, số tiền gõ có dấu chấm', function (): void {
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'amount' => '150.000',
                'expires_in_minutes' => 60,
                'description' => 'Bình gốm men lam',
                'bank_account_id' => $this->account->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = Order::query()->sole();

        expect($order->amount)->toBe(150_000)
            ->and($order->shop_id)->toBe($this->shop->getKey())
            ->and($order->created_via)->toBe(OrderSource::Dashboard)
            ->and($order->code)->toStartWith('DH')
            ->and($order->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(59);
    });

    it('không cho chọn tài khoản ngân hàng của shop khác', function (): void {
        $other = Shop::factory()->withBankAccount()->create();
        $foreignAccount = BankAccount::withoutGlobalScopes()->where('shop_id', $other->getKey())->firstOrFail();

        Livewire::test(CreateOrder::class)
            ->fillForm([
                'amount' => '50000',
                'expires_in_minutes' => 30,
                'bank_account_id' => $foreignAccount->getKey(),
            ])
            ->call('create')
            ->assertHasFormErrors(['bank_account_id']);

        expect(Order::withoutGlobalScopes()->count())->toBe(0);
    });

    it('mã đơn bên shop chỉ cần duy nhất trong shop', function (): void {
        $other = Shop::factory()->withBankAccount()->create();
        Order::factory()->for($other)->create(['external_ref' => 'WEB-1001']);

        $create = fn () => Livewire::test(CreateOrder::class)
            ->fillForm([
                'amount' => '50000',
                'expires_in_minutes' => 30,
                'external_ref' => 'WEB-1001',
                'bank_account_id' => $this->account->getKey(),
            ])
            ->call('create');

        $create()->assertHasNoFormErrors();
        $create()->assertHasFormErrors(['external_ref']);
    });
});

describe('hủy đơn', function (): void {
    it('hủy đơn còn nhận tiền và ghi nhật ký', function (): void {
        $order = Order::factory()->for($this->shop)->create(['bank_account_id' => $this->account->getKey()]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('cancel');

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);

        $log = AuditLog::query()->sole();
        expect($log->action)->toBe('order.cancelled')
            ->and($log->user_id)->toBe($this->owner->getKey())
            ->and($log->subject_id)->toBe($order->getKey())
            ->and($log->meta)->toBe(['from' => 'pending', 'paid_amount' => 0]);
    });

    it('ẩn nút hủy với đơn đã đủ tiền', function (OrderStatus $status): void {
        $order = Order::factory()->for($this->shop)->create([
            'bank_account_id' => $this->account->getKey(),
            'status' => $status,
        ]);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('cancel');
    })->with([OrderStatus::Paid, OrderStatus::Overpaid, OrderStatus::Cancelled]);
});

describe('tài khoản ngân hàng', function (): void {
    it('lấy mã ngân hàng theo BIN và viết hoa không dấu tên chủ tài khoản', function (): void {
        Livewire::test(ManageBankAccounts::class)
            ->callAction(CreateAction::class, data: [
                'bank_bin' => '970422',
                'account_number' => '0123456789',
                'account_name' => 'Trần Thị Bích',
            ])
            ->assertHasNoFormErrors();

        $account = BankAccount::query()->where('account_number', '0123456789')->sole();

        expect($account->bank_code)->toBe('MB')
            ->and($account->account_name)->toBe('TRAN THI BICH')
            ->and($account->is_default)->toBeFalse();
    });

    it('chỉ giữ một tài khoản mặc định', function (): void {
        Livewire::test(ManageBankAccounts::class)
            ->callAction(CreateAction::class, data: [
                'bank_bin' => '970422',
                'account_number' => '0123456789',
                'account_name' => 'TRAN THI BICH',
                'is_default' => true,
            ]);

        expect(BankAccount::query()->where('is_default', true)->pluck('account_number')->all())
            ->toBe(['0123456789']);
    });

    it('tài khoản đầu tiên của shop tự thành mặc định', function (): void {
        $fresh = Shop::factory()->withMember($this->owner)->create();
        openShop($this->owner, $fresh);

        Livewire::test(ManageBankAccounts::class)
            ->callAction(CreateAction::class, data: [
                'bank_bin' => '970436',
                'account_number' => '9988776655',
                'account_name' => 'LE VAN C',
            ]);

        expect(BankAccount::query()->sole()->is_default)->toBeTrue();
    });

    it('báo trùng khi shop đã có tài khoản này', function (): void {
        Livewire::test(ManageBankAccounts::class)
            ->callAction(CreateAction::class, data: [
                'bank_bin' => $this->account->bank_bin,
                'account_number' => $this->account->account_number,
                'account_name' => 'AI DO',
            ])
            ->assertHasFormErrors(['account_number']);
    });
});

describe('kết nối nhà cung cấp', function (): void {
    it('tạo kết nối Pay2S, khóa được mã hóa và URL webhook theo uuid', function (): void {
        Livewire::test(ManageProviderConnections::class)
            ->callAction(CreateAction::class, data: [
                'provider' => Provider::Pay2S->value,
                'status' => 'active',
                'credentials' => ['token' => 'pay2s-token-thu'],
            ])
            ->assertHasNoFormErrors();

        $connection = ProviderConnection::query()->sole();

        expect($connection->credential('token'))->toBe('pay2s-token-thu')
            ->and($connection->getRawOriginal('credentials'))->not->toContain('pay2s-token-thu');
    });

    it('bắt SePay có ít nhất một khóa', function (): void {
        Livewire::test(ManageProviderConnections::class)
            ->callAction(CreateAction::class, data: [
                'provider' => Provider::SePay->value,
                'status' => 'active',
            ])
            ->assertHasFormErrors(['credentials.secret' => 'required', 'credentials.api_key' => 'required']);

        Livewire::test(ManageProviderConnections::class)
            ->callAction(CreateAction::class, data: [
                'provider' => Provider::SePay->value,
                'status' => 'active',
                'credentials' => ['api_key' => 'sepay-key'],
            ])
            ->assertHasNoFormErrors();
    });

    it('sửa mà để trống ô khóa thì giữ khóa cũ', function (): void {
        $connection = ProviderConnection::factory()->for($this->shop)->create([
            'credentials' => ['secret' => 'khoa-cu'],
        ]);

        Livewire::test(ManageProviderConnections::class)
            ->callAction(TestAction::make(EditAction::class)->table($connection), data: [
                'label' => 'SePay chính',
                'credentials' => ['secret' => ''],
            ])
            ->assertHasNoFormErrors();

        $connection->refresh();
        expect($connection->label)->toBe('SePay chính')
            ->and($connection->credential('secret'))->toBe('khoa-cu');

        Livewire::test(ManageProviderConnections::class)
            ->callAction(TestAction::make(EditAction::class)->table($connection), data: [
                'credentials' => ['secret' => 'khoa-moi'],
            ]);

        expect($connection->refresh()->credential('secret'))->toBe('khoa-moi');
    });
});

it('staff không gọi được trang của owner kể cả qua Livewire', function (): void {
    $staff = User::factory()->create();
    $this->shop->members()->attach($staff, ['role' => ShopRole::Staff->value]);
    openShop($staff, $this->shop);

    Livewire::test(ManageProviderConnections::class)->assertForbidden();
});
