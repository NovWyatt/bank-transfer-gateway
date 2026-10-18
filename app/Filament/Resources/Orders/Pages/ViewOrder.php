<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\CancelOrder;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Order $record
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openPayPage')
                ->label('Mở trang thanh toán')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (Order $record): string => route('pay.show', $record->code))
                ->openUrlInNewTab(),
            Action::make('cancel')
                ->label('Hủy đơn')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (Order $record): bool => $record->status->acceptsPayment())
                ->requiresConfirmation()
                ->modalHeading(fn (Order $record): string => "Hủy đơn {$record->code}?")
                ->modalDescription(fn (Order $record): string => $record->paid_amount > 0
                    ? 'Đơn đã nhận một phần tiền. Sau khi hủy, shop tự hoàn số tiền này cho khách; tiền chuyển thêm vào đơn sẽ để chưa khớp.'
                    : 'Trang thanh toán sẽ báo đơn đã hủy. Tiền chuyển vào sau đó sẽ để chưa khớp.')
                ->modalSubmitActionLabel('Hủy đơn')
                ->action(function (Order $record, CancelOrder $cancel): void {
                    /** @var User $user */
                    $user = auth()->user();

                    try {
                        $cancel->handle($record, $user, request()->ip());
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $this->record->refresh();
                    Notification::make()->success()->title("Đã hủy đơn {$record->code}.")->send();
                }),
        ];
    }
}
