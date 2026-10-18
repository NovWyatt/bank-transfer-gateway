<?php

namespace App\Filament\Members;

use App\Actions\Members\InviteMember;
use App\Actions\Members\RevokeInvitation;
use App\Filament\Concerns\ShowsInvitationLink;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use App\Tenancy\ShopAccess;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

/**
 * Lời mời chưa ai nhận, ở cuối trang Thành viên. Nằm ngoài app/Filament/Widgets
 * để không bị tự đưa lên trang tổng quan.
 */
class PendingInvitations extends TableWidget
{
    use ShowsInvitationLink;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return ShopAccess::isOwner();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Lời mời chưa nhận')
            ->query(fn (): Builder => ShopInvitation::query()
                ->where('shop_id', $this->shop()->getKey())
                ->open()
                ->with('inviter'))
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('email')
                    ->label('Email'),
                TextColumn::make('role')
                    ->label('Vai trò')
                    ->badge(),
                TextColumn::make('inviter.name')
                    ->label('Người mời')
                    ->placeholder('Không rõ'),
                TextColumn::make('expires_at')
                    ->label('Hạn link')
                    // Ghi giờ cụ thể thay vì "còn 6 ngày": Carbon làm tròn xuống nên lời mời 7 ngày hiện thiếu một ngày.
                    ->state(fn (ShopInvitation $record): string => $record->expires_at->isPast()
                        ? 'Đã hết hạn'
                        : 'Đến '.$record->expires_at->setTimezone(FilamentTimezone::get())->format('H:i, d/m'))
                    ->color(fn (ShopInvitation $record): ?string => $record->expires_at->isPast() ? 'warning' : null),
            ])
            ->recordActions([
                Action::make('renew')
                    ->label('Tạo link mới')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (ShopInvitation $record): string => "Tạo link mới cho {$record->email}?")
                    ->modalDescription('Link cũ sẽ không dùng được nữa. Hạn link tính lại từ bây giờ.')
                    ->modalSubmitActionLabel('Tạo link mới')
                    ->action(function (ShopInvitation $record, InviteMember $invite): void {
                        try {
                            $issued = $invite->handle($this->shop(), $record->email, $record->role, $this->user(), request()->ip());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        $this->showInvitationLink($issued);
                    }),
                Action::make('revoke')
                    ->label('Thu hồi')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (ShopInvitation $record): string => "Thu hồi lời mời gửi {$record->email}?")
                    ->modalDescription('Link đã gửi sẽ không dùng được nữa.')
                    ->modalSubmitActionLabel('Thu hồi')
                    ->action(function (ShopInvitation $record, RevokeInvitation $revoke): void {
                        try {
                            $revoke->handle($record, $this->user(), request()->ip());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Đã thu hồi lời mời.')->send();
                    }),
            ])
            ->emptyStateHeading('Không có lời mời nào đang chờ')
            ->emptyStateDescription('Bấm "Mời thành viên" để tạo link mời.')
            ->emptyStateIcon(Heroicon::OutlinedUserPlus);
    }

    /** Trang Thành viên vừa tạo lời mời: vẽ lại bảng. */
    #[On('invitations-changed')]
    public function refreshInvitations(): void {}

    private function shop(): Shop
    {
        $shop = Filament::getTenant();

        return $shop instanceof Shop ? $shop : abort(404);
    }

    private function user(): User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
