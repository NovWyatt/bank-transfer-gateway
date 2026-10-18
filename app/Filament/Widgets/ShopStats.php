<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransferDirection;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Transaction;
use App\Support\Vnd;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ba con số shop cần nhìn mỗi sáng. Các truy vấn đều qua global scope của shop đang mở.
 */
class ShopStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        // "Hôm nay" theo múi giờ của shop, đổi về UTC để so với cột lưu ở UTC.
        $startOfToday = now($shop->timezone)->startOfDay()->utc();

        $receivedToday = (int) Transaction::query()
            ->where('direction', TransferDirection::In)
            ->where('status', TransactionStatus::Matched)
            ->where('transacted_at', '>=', $startOfToday)
            ->sum('amount');

        $waiting = Order::query()
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::PartiallyPaid])
            ->where('expires_at', '>', now())
            ->count();

        $unmatched = Transaction::query()
            ->where('direction', TransferDirection::In)
            ->where('status', TransactionStatus::Unmatched)
            ->count();

        return [
            Stat::make('Đã nhận hôm nay', Vnd::format($receivedToday))
                ->description('Tiền vào đã khớp đơn, tính từ 0 giờ'),
            Stat::make('Đơn đang chờ tiền', (string) $waiting)
                ->description('Chưa trả hoặc trả thiếu, còn hạn')
                ->url(OrderResource::getUrl()),
            Stat::make('Giao dịch chưa khớp', (string) $unmatched)
                ->description($unmatched > 0 ? 'Tiền đã vào nhưng chưa gắn được đơn, cần xem' : 'Không có khoản nào cần xem')
                ->descriptionColor($unmatched > 0 ? 'warning' : 'gray')
                ->url(TransactionResource::getUrl()),
        ];
    }
}
