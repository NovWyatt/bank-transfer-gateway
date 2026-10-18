<?php

namespace App\Api;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Quyền của một token API. Token gắn với shop, không với người dùng (mục 5.6 của kế hoạch).
 */
enum TokenAbility: string implements HasDescription, HasLabel
{
    case OrdersRead = 'orders:read';
    case OrdersWrite = 'orders:write';
    case TransactionsRead = 'transactions:read';
    case WebhooksWrite = 'webhooks:write';

    public function getLabel(): string
    {
        return $this->value;
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::OrdersRead => 'Xem đơn và trạng thái đơn',
            self::OrdersWrite => 'Tạo đơn, hủy đơn',
            self::TransactionsRead => 'Xem giao dịch ngân hàng đã nhận',
            self::WebhooksWrite => 'Gửi thử webhook tới endpoint của shop',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $ability): string => $ability->value, self::cases());
    }
}
