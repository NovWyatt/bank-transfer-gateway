<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\BankAccount;
use App\Models\Order;
use App\Support\Vnd;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'đơn';

    protected static ?string $pluralModelLabel = 'Đơn hàng';

    /** Filament mặc định viết hoa từng chữ ("Đơn Hàng"); tiếng Việt chỉ hoa chữ đầu. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'code';

    /** Chuỗi VietQR cho trường số tiền tối đa 13 ký tự. */
    private const MAX_AMOUNT = 9_999_999_999_999;

    /** @var array<int, string> phút => nhãn */
    private const EXPIRY_CHOICES = [
        15 => '15 phút',
        30 => '30 phút',
        60 => '1 giờ',
        180 => '3 giờ',
        1440 => '1 ngày',
        4320 => '3 ngày',
        10080 => '7 ngày',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('amount')
                            ->label('Số tiền')
                            ->required()
                            // Gõ 150000 hiện "150.000"; bỏ dấu chấm trước khi kiểm tra và lưu.
                            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
                            ->stripCharacters('.')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(self::MAX_AMOUNT)
                            ->suffix('đ')
                            ->autofocus(),
                        Select::make('expires_in_minutes')
                            ->label('Hết hạn sau')
                            ->options(self::EXPIRY_CHOICES)
                            ->default(30)
                            ->required()
                            ->selectablePlaceholder(false),
                        TextInput::make('description')
                            ->label('Mô tả')
                            ->helperText('Khách thấy dòng này trên trang thanh toán.')
                            ->maxLength(255),
                        TextInput::make('customer_name')
                            ->label('Tên khách')
                            ->maxLength(255),
                        TextInput::make('external_ref')
                            ->label('Mã đơn bên shop')
                            ->helperText('Không bắt buộc. Mỗi mã chỉ dùng cho một đơn.')
                            ->maxLength(100)
                            ->scopedUnique(Order::class, 'external_ref'),
                        Select::make('bank_account_id')
                            ->label('Nhận vào tài khoản')
                            ->options(fn (): array => self::bankAccountOptions())
                            ->default(fn (): ?int => BankAccount::query()->where('is_default', true)->value('id'))
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText(fn (): ?string => self::bankAccountOptions() === []
                                ? 'Shop chưa có tài khoản ngân hàng. Chủ shop thêm ở mục Tài khoản ngân hàng.'
                                : null),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Đơn')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('code')
                            ->label('Mã đơn')
                            ->weight(FontWeight::Medium)
                            ->copyable(),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge(),
                        TextEntry::make('paid_after_expiry')
                            ->label('Trả sau hạn')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Có, trong thời gian ân hạn' : 'Không'),
                        TextEntry::make('amount')
                            ->label('Giá đơn')
                            ->formatStateUsing(fn (int $state): string => Vnd::format($state)),
                        TextEntry::make('paid_amount')
                            ->label('Đã nhận')
                            ->formatStateUsing(fn (int $state): string => Vnd::format($state)),
                        TextEntry::make('remaining')
                            ->label('Còn thiếu')
                            ->state(fn (Order $record): string => Vnd::format($record->remainingAmount())),
                        TextEntry::make('description')
                            ->label('Mô tả')
                            ->placeholder('Không có'),
                        TextEntry::make('customer_name')
                            ->label('Tên khách')
                            ->placeholder('Không có'),
                        TextEntry::make('external_ref')
                            ->label('Mã đơn bên shop')
                            ->placeholder('Không có'),
                        TextEntry::make('created_at')
                            ->label('Tạo lúc')
                            ->dateTime('H:i, d/m/Y'),
                        TextEntry::make('expires_at')
                            ->label('Hết hạn lúc')
                            ->dateTime('H:i, d/m/Y'),
                        TextEntry::make('paid_at')
                            ->label('Đủ tiền lúc')
                            ->dateTime('H:i, d/m/Y')
                            ->placeholder('Chưa đủ'),
                        TextEntry::make('bankAccount.account_number')
                            ->label('Tài khoản nhận')
                            ->state(fn (Order $record): string => self::accountLabel($record->bankAccount)),
                        TextEntry::make('created_via')
                            ->label('Tạo từ'),
                        TextEntry::make('pay_url')
                            ->label('Link thanh toán')
                            ->state(fn (Order $record): string => route('pay.show', $record->code))
                            ->copyable(),
                    ]),
                Section::make('Giao dịch đã khớp')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('transactions')
                            ->hiddenLabel()
                            ->placeholder('Chưa có giao dịch nào khớp với đơn này.')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('transacted_at')
                                    ->label('Lúc')
                                    ->dateTime('H:i:s, d/m/Y'),
                                TextEntry::make('amount')
                                    ->label('Số tiền')
                                    ->formatStateUsing(fn (int $state): string => Vnd::format($state)),
                                TextEntry::make('provider')
                                    ->label('Qua'),
                                TextEntry::make('content')
                                    ->label('Nội dung'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // Đơn đổi trạng thái khi tiền về; bảng tự cập nhật thay vì bắt người dùng tải lại.
            ->poll('15s')
            ->columns([
                TextColumn::make('code')
                    ->label('Mã đơn')
                    ->weight(FontWeight::Medium)
                    ->searchable()
                    ->copyable(),
                TextColumn::make('amount')
                    ->label('Giá đơn')
                    ->formatStateUsing(fn (int $state): string => Vnd::format($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Đã nhận')
                    ->formatStateUsing(fn (int $state): string => Vnd::format($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('customer_name')
                    ->label('Khách')
                    ->searchable()
                    ->placeholder('Không có')
                    ->toggleable(),
                TextColumn::make('external_ref')
                    ->label('Mã bên shop')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('H:i, d/m')
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Hết hạn')
                    ->dateTime('H:i, d/m')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(OrderStatus::class)
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Chưa có đơn nào')
            ->emptyStateDescription('Tạo đơn để có link thanh toán và mã QR gửi khách.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    /** "VCB · 1017588888 · NGUYEN VAN A" */
    public static function accountLabel(BankAccount $account): string
    {
        return implode(' · ', [$account->bank()->shortName ?? $account->bank_code, $account->account_number, $account->account_name]);
    }

    /** @return array<int, string> */
    private static function bankAccountOptions(): array
    {
        return BankAccount::query()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (BankAccount $account): array => [$account->getKey() => self::accountLabel($account)])
            ->all();
    }
}
