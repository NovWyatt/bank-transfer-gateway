<?php

namespace App\Filament\Resources\ProviderConnections;

use App\Enums\ConnectionStatus;
use App\Enums\Provider;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\ProviderConnections\Pages\ManageProviderConnections;
use App\Models\BankAccount;
use App\Models\ProviderConnection;
use App\Tenancy\ShopAccess;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Điểm nhận webhook của từng nhà cung cấp. Chỉ owner: khóa xác thực quyết định ai được báo "đã nhận tiền".
 * Không có nút xóa: xóa kết nối kéo theo nhật ký webhook thô. Muốn ngừng nhận thì tạm dừng.
 */
class ProviderConnectionResource extends Resource
{
    protected static ?string $model = ProviderConnection::class;

    /** Quan hệ trên Shop tên là connections(), không phải providerConnections(). */
    protected static ?string $tenantRelationshipName = 'connections';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'kết nối';

    protected static ?string $pluralModelLabel = 'Kết nối nhận tiền';

    /** Filament mặc định viết hoa từng chữ ("Đơn Hàng"); tiếng Việt chỉ hoa chữ đầu. */
    protected static bool $hasTitleCaseModelLabel = false;

    public static function canAccess(): bool
    {
        return ShopAccess::isOwner();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('provider')
                    ->label('Nhà cung cấp')
                    ->options(self::providerOptions())
                    ->required()
                    ->live()
                    // Đổi nhà cung cấp thì khóa cũ và URL webhook đã dán bên kia đều sai: tạo kết nối mới.
                    ->disabledOn('edit'),
                TextInput::make('label')
                    ->label('Tên gợi nhớ')
                    ->placeholder('Ví dụ: SePay - VCB chính')
                    ->maxLength(255),
                Select::make('bank_account_id')
                    ->label('Tài khoản ngân hàng')
                    ->options(fn (): array => BankAccount::query()
                        ->orderByDesc('is_default')
                        ->get()
                        ->mapWithKeys(fn (BankAccount $account): array => [$account->getKey() => OrderResource::accountLabel($account)])
                        ->all())
                    ->placeholder('Theo số tài khoản trong webhook')
                    ->helperText('Bỏ trống nếu một kết nối báo cho nhiều tài khoản: app khớp theo số tài khoản nhà cung cấp gửi kèm.'),
                Select::make('status')
                    ->label('Trạng thái')
                    ->options(ConnectionStatus::class)
                    ->default(ConnectionStatus::Active)
                    ->required()
                    ->selectablePlaceholder(false)
                    ->helperText('Tạm dừng: vẫn trả 200 cho nhà cung cấp nhưng không lưu, không khớp.'),
                Section::make('Khóa xác thực')
                    ->description(fn (string $operation): string => $operation === 'edit'
                        ? 'Khóa đã lưu không hiện lại. Bỏ trống ô nào thì giữ khóa cũ ở ô đó.'
                        : 'Lấy ở trang cấu hình webhook của nhà cung cấp. Khóa được mã hóa trước khi lưu.')
                    ->visible(fn (Get $get): bool => self::selectedProvider($get) !== null)
                    ->schema(self::credentialInputs()),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('provider')
                    ->label('Nhà cung cấp')
                    ->weight(FontWeight::Medium)
                    ->description(fn (ProviderConnection $record): ?string => $record->label),
                TextColumn::make('webhook_url')
                    ->label('URL webhook')
                    ->state(fn (ProviderConnection $record): string => self::webhookUrl($record))
                    ->copyable()
                    ->copyMessage('Đã chép URL webhook')
                    ->limit(36)
                    ->tooltip('Bấm để chép, rồi dán vào trang cấu hình webhook của nhà cung cấp'),
                TextColumn::make('bankAccount.account_number')
                    ->label('Tài khoản')
                    ->placeholder('Theo webhook'),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('last_received_at')
                    ->label('Nhận lần cuối')
                    ->since()
                    ->dateTimeTooltip('H:i:s, d/m/Y')
                    ->placeholder('Chưa nhận'),
            ])
            ->recordActions([
                EditAction::make()
                    // Ô khóa bỏ trống không được gửi lên: giữ khóa cũ, chỉ thay khóa vừa nhập.
                    ->mutateDataUsing(function (array $data, ProviderConnection $record): array {
                        $data['credentials'] = array_merge($record->credentials ?? [], $data['credentials'] ?? []);

                        return $data;
                    }),
            ])
            ->emptyStateHeading('Chưa có kết nối nào')
            ->emptyStateDescription('Thêm kết nối SePay hoặc Pay2S, rồi dán URL webhook vào trang của nhà cung cấp.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProviderConnections::route('/'),
        ];
    }

    public static function webhookUrl(ProviderConnection $connection): string
    {
        return route('hooks.receive', ['provider' => $connection->provider->value, 'uuid' => $connection->uuid]);
    }

    /** @return array<string, string> */
    private static function providerOptions(): array
    {
        $options = [];

        foreach (Provider::cases() as $provider) {
            // Nhà cung cấp chưa có adapter thì chưa cho chọn.
            if ($provider->credentialFields() !== []) {
                $options[$provider->value] = $provider->label();
            }
        }

        return $options;
    }

    /**
     * Một ô cho mỗi khóa, hiện theo nhà cung cấp đang chọn. SePay và Giả lập cùng dùng "secret".
     *
     * @return list<TextInput>
     */
    private static function credentialInputs(): array
    {
        $labels = [];

        foreach (Provider::cases() as $provider) {
            $labels += $provider->credentialFields();
        }

        $inputs = [];

        foreach ($labels as $key => $label) {
            $inputs[] = TextInput::make("credentials.{$key}")
                ->label($label)
                ->password()
                ->revealable()
                ->autocomplete('off')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => array_key_exists($key, self::selectedProvider($get)?->credentialFields() ?? []))
                ->required(fn (Get $get, string $operation): bool => $operation === 'create' && self::isRequiredCredential($get, $key))
                ->helperText(fn (Get $get): ?string => self::selectedProvider($get) === Provider::SePay
                    ? 'SePay: điền một trong hai, khóa ký HMAC hoặc API key, đúng kiểu xác thực đã chọn bên SePay.'
                    : null)
                // Ô trống không gửi lên, để khi sửa không ghi đè khóa cũ bằng chuỗi rỗng.
                ->dehydrated(fn (?string $state): bool => filled($state));
        }

        return $inputs;
    }

    /**
     * Khi tạo kết nối: ô của nhà cung cấp đang chọn là bắt buộc. Riêng SePay nhận HMAC hoặc API key,
     * nên một ô chỉ bắt buộc khi các ô còn lại của SePay đều trống.
     */
    private static function isRequiredCredential(Get $get, string $key): bool
    {
        $fields = self::selectedProvider($get)?->credentialFields() ?? [];

        if (! array_key_exists($key, $fields)) {
            return false;
        }

        if (self::selectedProvider($get) !== Provider::SePay) {
            return true;
        }

        foreach (array_keys($fields) as $other) {
            if ($other !== $key && filled($get("credentials.{$other}"))) {
                return false;
            }
        }

        return true;
    }

    private static function selectedProvider(Get $get): ?Provider
    {
        $value = $get('provider');

        return $value instanceof Provider ? $value : Provider::tryFrom((string) $value);
    }
}
