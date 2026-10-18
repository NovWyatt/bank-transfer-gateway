<?php

namespace App\Filament\Pages;

use App\Actions\Members\ChangeMemberRole;
use App\Actions\Members\InviteMember;
use App\Actions\Members\RemoveMember;
use App\Enums\ShopRole;
use App\Filament\Concerns\ShowsInvitationLink;
use App\Filament\Members\PendingInvitations;
use App\Models\Membership;
use App\Models\Shop;
use App\Models\User;
use App\Tenancy\ShopAccess;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Thành viên của shop đang mở. Chỉ owner: mời, đổi vai trò, xóa.
 * App không gửi email: lời mời là một link owner tự gửi cho người được mời.
 */
class Members extends Page implements HasTable
{
    use InteractsWithTable;
    use ShowsInvitationLink;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Thành viên';

    protected static ?string $title = 'Thành viên';

    protected static ?string $slug = 'members';

    public static function canAccess(): bool
    {
        return ShopAccess::isOwner();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getFooterWidgets(): array
    {
        return [PendingInvitations::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Mời thành viên')
                ->icon(Heroicon::OutlinedUserPlus)
                ->modalHeading('Mời thành viên')
                ->modalDescription('App không gửi email. Tạo xong, bạn chép link và gửi cho người được mời.')
                ->schema([
                    TextInput::make('email')
                        ->label('Email của người được mời')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Select::make('role')
                        ->label('Vai trò')
                        ->options(ShopRole::class)
                        ->default(ShopRole::Staff)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->helperText('Nhân viên xem và tạo đơn. Chủ shop quản lý thêm tài khoản ngân hàng, kết nối và thành viên.'),
                ])
                ->modalSubmitActionLabel('Tạo link mời')
                ->action(function (array $data, InviteMember $invite, Action $action): void {
                    try {
                        $issued = $invite->handle($this->shop(), $data['email'], self::role($data['role']), $this->user(), request()->ip());
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    $this->dispatch('invitations-changed');
                    $this->showInvitationLink($issued);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            // Membership không có global scope theo shop: lọc tường minh. Hành động trên dòng
            // chỉ tìm được bản ghi trong truy vấn này, nên id của shop khác không với tới được.
            ->query(fn (): Builder => Membership::query()
                ->where('shop_id', $this->shop()->getKey())
                ->with('user'))
            ->defaultSort('created_at')
            ->paginated(false)
            ->columns([
                TextColumn::make('user.name')
                    ->label('Tên')
                    ->weight(FontWeight::Medium)
                    ->description(fn (Membership $record): ?string => $this->isSelf($record) ? 'Bạn' : null),
                TextColumn::make('user.email')
                    ->label('Email')
                    ->copyable(),
                TextColumn::make('role')
                    ->label('Vai trò')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Tham gia')
                    ->date('d/m/Y'),
            ])
            ->recordActions([
                // Không cho tự đổi hay tự xóa mình: lỡ tay là mất quyền vào trang này ngay.
                Action::make('changeRole')
                    ->label('Đổi vai trò')
                    ->icon(Heroicon::OutlinedArrowsUpDown)
                    ->color('gray')
                    ->hidden(fn (Membership $record): bool => $this->isSelf($record))
                    ->modalHeading(fn (Membership $record): string => "Đổi vai trò của {$record->user->name}")
                    ->schema([
                        Select::make('role')
                            ->label('Vai trò')
                            ->options(ShopRole::class)
                            ->required()
                            ->selectablePlaceholder(false),
                    ])
                    ->fillForm(fn (Membership $record): array => ['role' => $record->role])
                    ->action(function (Membership $record, array $data, ChangeMemberRole $change, Action $action): void {
                        try {
                            $change->handle($record, self::role($data['role']), $this->user(), request()->ip());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();

                            return;
                        }

                        Notification::make()->success()->title('Đã đổi vai trò.')->send();
                    }),
                Action::make('remove')
                    ->label('Xóa khỏi shop')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->hidden(fn (Membership $record): bool => $this->isSelf($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Membership $record): string => "Xóa {$record->user->name} khỏi shop?")
                    ->modalDescription('Người này không vào được shop nữa. Tài khoản của họ vẫn còn, mời lại được khi cần.')
                    ->modalSubmitActionLabel('Xóa khỏi shop')
                    ->action(function (Membership $record, RemoveMember $remove): void {
                        try {
                            $remove->handle($record, $this->user(), request()->ip());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("Đã xóa {$record->user->name} khỏi shop.")->send();
                    }),
            ]);
    }

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

    private function isSelf(Membership $record): bool
    {
        return $record->user_id === Filament::auth()->id();
    }

    /** Select trả về enum hoặc chuỗi tùy lúc (điền sẵn hay người dùng chọn). */
    private static function role(mixed $value): ShopRole
    {
        return $value instanceof ShopRole ? $value : ShopRole::from((string) $value);
    }
}
