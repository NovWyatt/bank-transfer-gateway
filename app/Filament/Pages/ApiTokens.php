<?php

namespace App\Filament\Pages;

use App\Api\TokenAbility;
use App\Filament\Concerns\RevealsSecretOnce;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use App\Tenancy\ShopAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
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
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token API của shop đang mở. Chỉ owner: tạo (hiện một lần), thu hồi.
 * Token gắn với shop, không với người tạo: owner rời shop thì tích hợp vẫn chạy.
 */
class ApiTokens extends Page implements HasTable
{
    use InteractsWithTable;
    use RevealsSecretOnce;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Token API';

    protected static ?string $title = 'Token API';

    protected static ?string $slug = 'api-tokens';

    /** @var array<int, string> số ngày => nhãn; 0 là không hết hạn */
    private const EXPIRY_CHOICES = [
        0 => 'Không hết hạn',
        30 => '30 ngày',
        90 => '90 ngày',
        365 => '1 năm',
    ];

    public static function canAccess(): bool
    {
        return ShopAccess::isOwner();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('docs')
                ->label('Tài liệu API')
                ->icon(Heroicon::OutlinedBookOpen)
                ->color('gray')
                ->url(url('/docs/api'))
                ->openUrlInNewTab(),
            Action::make('createToken')
                ->label('Tạo token')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Tạo token API')
                ->modalDescription('Gửi token trong header Authorization: Bearer <token>. Chỉ chọn quyền mà hệ thống gọi API thật sự cần.')
                ->schema([
                    TextInput::make('name')
                        ->label('Tên gợi nhớ')
                        ->placeholder('Ví dụ: WooCommerce cửa hàng chính')
                        ->required()
                        ->maxLength(100),
                    CheckboxList::make('abilities')
                        ->label('Quyền')
                        ->options(TokenAbility::class)
                        ->default([TokenAbility::OrdersRead->value, TokenAbility::OrdersWrite->value])
                        ->required(),
                    Select::make('expires_in_days')
                        ->label('Hết hạn')
                        ->options(self::EXPIRY_CHOICES)
                        ->default(0)
                        ->required()
                        ->selectablePlaceholder(false),
                ])
                ->modalSubmitActionLabel('Tạo token')
                ->action(function (array $data): void {
                    $abilities = self::abilityValues($data['abilities']);
                    $days = (int) $data['expires_in_days'];

                    $token = $this->shop()->createToken($data['name'], $abilities, $days > 0 ? now()->addDays($days) : null);

                    AuditLog::record($this->shop()->getKey(), $this->user(), 'api_token.created', $token->accessToken, [
                        'name' => $data['name'],
                        'abilities' => $abilities,
                    ], request()->ip());

                    $this->revealSecret(
                        heading: "Token \"{$data['name']}\" đã tạo",
                        description: 'Chép token và cất vào biến môi trường của hệ thống gọi API. App chỉ lưu bản băm, '
                            .'nên đóng hộp này là không xem lại được: lỡ mất thì thu hồi rồi tạo token mới.',
                        secret: $token->plainTextToken,
                    );
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            // Chỉ token của shop đang mở; thu hồi chỉ tìm được bản ghi trong truy vấn này.
            ->query(fn (): Builder => $this->shop()->tokens()->getQuery())
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Tên')
                    ->weight(FontWeight::Medium),
                TextColumn::make('abilities')
                    ->label('Quyền')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('last_used_at')
                    ->label('Dùng lần cuối')
                    ->since()
                    ->dateTimeTooltip('H:i:s, d/m/Y')
                    ->placeholder('Chưa dùng'),
                TextColumn::make('expires_at')
                    ->label('Hết hạn')
                    ->dateTime('d/m/Y')
                    ->placeholder('Không hết hạn')
                    ->color(fn (PersonalAccessToken $record): ?string => $record->expires_at?->isPast() ? 'danger' : null),
                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('H:i, d/m/Y'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Thu hồi')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (PersonalAccessToken $record): string => "Thu hồi token \"{$record->name}\"?")
                    ->modalDescription('Mọi request dùng token này bị từ chối ngay. Không hoàn tác được.')
                    ->modalSubmitActionLabel('Thu hồi')
                    ->action(function (PersonalAccessToken $record): void {
                        $record->delete();

                        AuditLog::record($this->shop()->getKey(), $this->user(), 'api_token.revoked', $record, [
                            'name' => $record->name,
                        ], request()->ip());

                        Notification::make()->success()->title("Đã thu hồi token \"{$record->name}\".")->send();
                    }),
            ])
            ->emptyStateHeading('Chưa có token nào')
            ->emptyStateDescription('Tạo token để hệ thống bán hàng của shop tạo đơn và đọc trạng thái qua API.');
    }

    /**
     * CheckboxList trả giá trị enum hoặc chuỗi tùy lúc (điền sẵn hay người dùng chọn).
     *
     * @param  array<int, mixed>  $selected
     * @return list<string>
     */
    private static function abilityValues(array $selected): array
    {
        return array_values(array_map(
            fn (mixed $ability): string => $ability instanceof TokenAbility ? $ability->value : (string) $ability,
            $selected,
        ));
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
}
