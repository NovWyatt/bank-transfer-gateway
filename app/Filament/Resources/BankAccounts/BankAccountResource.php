<?php

namespace App\Filament\Resources\BankAccounts;

use App\Filament\Resources\BankAccounts\Pages\ManageBankAccounts;
use App\Models\BankAccount;
use App\Payments\Banks\Bank;
use App\Payments\Banks\BankDirectory;
use App\Tenancy\ShopAccess;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Tài khoản nhận tiền của shop. Chỉ owner: số tài khoản quyết định tiền của khách đi đâu.
 */
class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'tài khoản ngân hàng';

    protected static ?string $pluralModelLabel = 'Tài khoản ngân hàng';

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
                Select::make('bank_bin')
                    ->label('Ngân hàng')
                    ->options(fn (): array => self::bankOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->in(fn (): array => array_keys(self::bankOptions())),
                TextInput::make('account_number')
                    ->label('Số tài khoản')
                    ->required()
                    ->regex('/^[0-9A-Za-z]{4,19}$/')
                    ->validationMessages([
                        'regex' => 'Số tài khoản gồm 4 đến 19 chữ số hoặc chữ cái, không có dấu cách.',
                        'unique' => 'Shop đã có tài khoản này.',
                    ])
                    ->scopedUnique(
                        BankAccount::class,
                        'account_number',
                        ignoreRecord: true,
                        modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->where('bank_bin', $get('bank_bin')),
                    ),
                TextInput::make('account_name')
                    ->label('Tên chủ tài khoản')
                    ->helperText('Viết như trên app ngân hàng. Tự chuyển sang chữ hoa không dấu.')
                    ->required()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (string $state): string => Str::upper(Str::ascii(trim($state)))),
                TextInput::make('label')
                    ->label('Tên gợi nhớ')
                    ->placeholder('Ví dụ: Tài khoản bán online')
                    ->maxLength(255),
                Toggle::make('is_default')
                    ->label('Tài khoản mặc định cho đơn mới')
                    ->helperText('Bật ở đây thì tài khoản mặc định cũ tự tắt.'),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('is_default', 'desc')
            ->columns([
                TextColumn::make('bank_code')
                    ->label('Ngân hàng')
                    ->state(fn (BankAccount $record): string => $record->bank()->shortName ?? $record->bank_code)
                    ->description(fn (BankAccount $record): ?string => $record->bank()?->name),
                TextColumn::make('account_number')
                    ->label('Số tài khoản')
                    ->weight(FontWeight::Medium)
                    ->copyable(),
                TextColumn::make('account_name')
                    ->label('Chủ tài khoản'),
                TextColumn::make('label')
                    ->label('Tên gợi nhớ')
                    ->placeholder('Không có'),
                IconColumn::make('is_default')
                    ->label('Mặc định')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(false),
            ])
            ->recordActions([
                EditAction::make(),
                // Đơn giữ khóa ngoại tới tài khoản (restrict): chỉ xóa được tài khoản chưa có đơn.
                DeleteAction::make()
                    ->visible(fn (BankAccount $record): bool => ! $record->is_default && $record->orders()->doesntExist()),
            ])
            ->emptyStateHeading('Chưa có tài khoản ngân hàng')
            ->emptyStateDescription('Thêm tài khoản nhận tiền trước khi tạo đơn. Tài khoản đầu tiên tự thành mặc định.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBankAccounts::route('/'),
        ];
    }

    /** @return array<string, string> BIN => "VCB · Vietcombank ..." */
    private static function bankOptions(): array
    {
        $supported = array_filter(app(BankDirectory::class)->all(), fn (Bank $bank): bool => $bank->transferSupported);
        uasort($supported, fn (Bank $a, Bank $b): int => strcmp($a->shortName, $b->shortName));

        return array_map(fn (Bank $bank): string => "{$bank->shortName} · {$bank->name}", $supported);
    }
}
