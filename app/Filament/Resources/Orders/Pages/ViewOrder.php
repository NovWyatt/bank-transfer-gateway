<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\CancelOrder;
use App\Actions\SimulateTransfer;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Support\Vnd;
use App\Tenancy\ShopAccess;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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
            Action::make('simulateTransfer')
                ->label('Giả lập khách chuyển khoản')
                ->icon(Heroicon::OutlinedBeaker)
                ->color('gray')
                ->visible(fn (Order $record): bool => config('payments.simulator_enabled') === true
                    && ShopAccess::isOwner()
                    && $record->status->acceptsPayment())
                ->modalHeading(fn (Order $record): string => "Giả lập khách chuyển khoản cho đơn {$record->code}")
                ->modalDescription('Tạo một giao dịch giả kiểu SePay và đưa qua đúng đường nhận webhook thật: kiểm chữ ký, khớp đơn, '
                    .'gửi webhook và tin Telegram. Không có tiền thật; giao dịch ghi nhà cung cấp là "Giả lập". Mở trang thanh toán '
                    .'ở tab khác để thấy nó tự đổi trạng thái.')
                ->schema([
                    TextInput::make('amount')
                        ->label('Số tiền (đ)')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1_000_000_000)
                        ->required()
                        ->helperText('Nhập ít hơn số còn thiếu để thử trả thiếu, nhiều hơn để thử trả dư.'),
                ])
                ->fillForm(fn (Order $record): array => ['amount' => $record->remainingAmount()])
                ->modalSubmitActionLabel('Chuyển giả')
                ->action(function (array $data, Order $record, SimulateTransfer $simulate): void {
                    $status = $simulate->handle($record, (int) $data['amount']);
                    $this->record->refresh();

                    if ($status !== 200) {
                        Notification::make()->danger()->title("Đường nhận trả HTTP {$status}, không ghi được giao dịch.")->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title('Đã giả lập +'.Vnd::format((int) $data['amount']))
                        ->body("Đơn {$this->record->code}: {$this->record->status->label()}.")
                        ->send();
                }),
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
