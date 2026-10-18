<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\CreateOrder as CreateOrderAction;
use App\Enums\OrderSource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\Shop;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * Tạo đơn qua action dùng chung với API: sinh mã, gán hạn, kiểm tra tài khoản thuộc shop.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Order
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return app(CreateOrderAction::class)->handle(
            shop: $shop,
            amount: (int) $data['amount'],
            source: OrderSource::Dashboard,
            externalRef: self::blankToNull($data['external_ref'] ?? null),
            description: self::blankToNull($data['description'] ?? null),
            customerName: self::blankToNull($data['customer_name'] ?? null),
            expiresInMinutes: (int) $data['expires_in_minutes'],
            // Tìm qua global scope của shop: id sửa tay sang shop khác sẽ không thấy.
            bankAccount: BankAccount::query()->findOrFail($data['bank_account_id']),
        );
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Đã tạo đơn. Gửi link thanh toán cho khách.';
    }

    private static function blankToNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
