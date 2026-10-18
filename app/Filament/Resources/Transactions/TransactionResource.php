<?php

namespace App\Filament\Resources\Transactions;

use App\Enums\Provider;
use App\Enums\TransactionStatus;
use App\Enums\TransferDirection;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\Pages\ViewTransaction;
use App\Models\Transaction;
use App\Support\Vnd;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Giao dịch nhận từ nhà cung cấp. Chỉ xem: dữ liệu do ngân hàng gửi, không sửa tay.
 * Khớp tay và duyệt gợi ý thuộc giai đoạn 2.
 */
class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'giao dịch';

    protected static ?string $pluralModelLabel = 'Giao dịch';

    /** Filament mặc định viết hoa từng chữ ("Đơn Hàng"); tiếng Việt chỉ hoa chữ đầu. */
    protected static bool $hasTitleCaseModelLabel = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Giao dịch')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('amount')
                            ->label('Số tiền')
                            ->weight(FontWeight::Medium)
                            ->formatStateUsing(fn (int $state): string => Vnd::format($state)),
                        TextEntry::make('direction')
                            ->label('Chiều'),
                        TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge(),
                        TextEntry::make('transacted_at')
                            ->label('Ngân hàng ghi nhận lúc')
                            ->dateTime('H:i:s, d/m/Y'),
                        TextEntry::make('created_at')
                            ->label('Nhận webhook lúc')
                            ->dateTime('H:i:s, d/m/Y'),
                        TextEntry::make('provider')
                            ->label('Qua'),
                        TextEntry::make('content')
                            ->label('Nội dung chuyển khoản')
                            ->columnSpanFull(),
                        TextEntry::make('payer_name')
                            ->label('Người chuyển')
                            ->placeholder('Ngân hàng không gửi'),
                        TextEntry::make('payer_account')
                            ->label('Tài khoản người chuyển')
                            ->placeholder('Ngân hàng không gửi'),
                        TextEntry::make('bankAccount.account_number')
                            ->label('Vào tài khoản')
                            ->state(fn (Transaction $record): ?string => $record->bankAccount === null
                                ? null
                                : OrderResource::accountLabel($record->bankAccount))
                            ->placeholder('Không xác định'),
                    ]),
                Section::make('Khớp đơn')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('order.code')
                            ->label('Đơn')
                            ->placeholder('Chưa khớp đơn nào')
                            ->url(fn (Transaction $record): ?string => self::orderUrl($record)),
                        TextEntry::make('matched_by')
                            ->label('Cách khớp')
                            ->placeholder('Chưa khớp'),
                        TextEntry::make('matched_at')
                            ->label('Khớp lúc')
                            ->dateTime('H:i:s, d/m/Y')
                            ->placeholder('Chưa khớp'),
                        TextEntry::make('duplicateSource.external_id')
                            ->label('Bản gốc (khi trùng)')
                            ->state(fn (Transaction $record): ?string => $record->duplicateSource === null
                                ? null
                                : "{$record->duplicateSource->provider->label()} #{$record->duplicateSource->external_id}")
                            ->placeholder('Không trùng'),
                        TextEntry::make('external_id')
                            ->label('Mã giao dịch của nhà cung cấp'),
                        TextEntry::make('reference_code')
                            ->label('Mã tham chiếu ngân hàng')
                            ->placeholder('Không có'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('transacted_at', 'desc')
            ->poll('15s')
            ->columns([
                TextColumn::make('transacted_at')
                    ->label('Lúc')
                    ->dateTime('H:i, d/m')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Số tiền')
                    ->formatStateUsing(fn (int $state, Transaction $record): string => ($record->direction === TransferDirection::Out ? '−' : '').Vnd::format($state))
                    ->color(fn (Transaction $record): ?string => $record->direction === TransferDirection::Out ? 'gray' : null)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('content')
                    ->label('Nội dung')
                    ->limit(48)
                    ->tooltip(fn (Transaction $record): ?string => mb_strlen((string) $record->content) > 48 ? $record->content : null)
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('order.code')
                    ->label('Đơn')
                    ->placeholder('Chưa khớp')
                    ->url(fn (Transaction $record): ?string => self::orderUrl($record)),
                TextColumn::make('provider')
                    ->label('Qua')
                    ->toggleable(),
                TextColumn::make('payer_name')
                    ->label('Người chuyển')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reference_code')
                    ->label('Mã tham chiếu')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(TransactionStatus::class)
                    ->multiple(),
                SelectFilter::make('provider')
                    ->label('Nhà cung cấp')
                    ->options(Provider::class),
                SelectFilter::make('direction')
                    ->label('Chiều')
                    ->options(TransferDirection::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Chưa nhận giao dịch nào')
            ->emptyStateDescription('Giao dịch hiện ở đây khi nhà cung cấp gửi webhook về kết nối của shop.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
            'view' => ViewTransaction::route('/{record}'),
        ];
    }

    private static function orderUrl(Transaction $record): ?string
    {
        return $record->order === null ? null : OrderResource::getUrl('view', ['record' => $record->order]);
    }
}
