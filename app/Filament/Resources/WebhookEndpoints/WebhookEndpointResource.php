<?php

namespace App\Filament\Resources\WebhookEndpoints;

use App\Enums\WebhookEventType;
use App\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Filament\Resources\WebhookEndpoints\Pages\ViewWebhookEndpoint;
use App\Filament\Resources\WebhookEndpoints\RelationManagers\DeliveriesRelationManager;
use App\Models\WebhookEndpoint;
use App\Rules\WebhookUrl;
use App\Tenancy\ShopAccess;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Endpoint trên hệ thống của shop nhận webhook có chữ ký. Chỉ owner: secret quyết định
 * phía shop tin webhook nào là thật.
 */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'endpoint';

    protected static ?string $pluralModelLabel = 'Webhook gửi đi';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'url';

    public static function canAccess(): bool
    {
        return ShopAccess::isOwner();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::formFields())->columns(1);
    }

    /**
     * Dùng chung cho hộp thêm endpoint (trang danh sách) và hộp sửa (trang xem).
     *
     * @return list<Component>
     */
    public static function formFields(): array
    {
        return [
            TextInput::make('url')
                ->label('URL nhận webhook')
                ->placeholder('https://shop-cua-ban.vn/webhooks/gateway')
                ->required()
                ->maxLength(2048)
                ->rule(new WebhookUrl)
                ->helperText('Chỉ https, trỏ tới máy chủ công khai. App gửi POST JSON có chữ ký và chờ phản hồi 2xx trong 10 giây.'),
            TextInput::make('description')
                ->label('Ghi chú')
                ->placeholder('Ví dụ: Máy chủ WooCommerce')
                ->maxLength(255),
            CheckboxList::make('events')
                ->label('Sự kiện gửi tới endpoint này')
                ->options(WebhookEventType::options())
                ->default(array_keys(WebhookEventType::options()))
                ->required()
                ->columns(2)
                ->bulkToggleable(),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('url')
                            ->label('URL')
                            ->weight(FontWeight::Medium)
                            ->copyable()
                            ->columnSpan(2),
                        TextEntry::make('active')
                            ->label('Trạng thái')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Đang bật' : 'Đã tắt')
                            ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                        TextEntry::make('disabled_reason')
                            ->label('Lý do tắt')
                            ->columnSpanFull()
                            ->color('danger')
                            ->visible(fn (WebhookEndpoint $record): bool => ! $record->active && $record->disabled_reason !== null),
                        TextEntry::make('description')
                            ->label('Ghi chú')
                            ->placeholder('Không có'),
                        TextEntry::make('last_success_at')
                            ->label('Gửi được lần cuối')
                            ->since()
                            ->dateTimeTooltip('H:i:s, d/m/Y')
                            ->placeholder('Chưa có'),
                        TextEntry::make('consecutive_failures')
                            ->label('Hỏng liên tiếp')
                            ->formatStateUsing(fn (int $state, WebhookEndpoint $record): string => $state === 0
                                ? 'Không'
                                : "{$state} lần, từ ".$record->failing_since?->diffForHumans())
                            ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                        TextEntry::make('events')
                            ->label('Sự kiện')
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (string $state): string => WebhookEventType::tryFrom($state)?->label() ?? $state)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('url')
                    ->label('URL')
                    ->weight(FontWeight::Medium)
                    ->limit(48)
                    ->description(fn (WebhookEndpoint $record): ?string => $record->description),
                TextColumn::make('events')
                    ->label('Sự kiện')
                    ->state(fn (WebhookEndpoint $record): string => count($record->events ?? []).' loại')
                    ->tooltip(fn (WebhookEndpoint $record): string => implode(', ', $record->events ?? [])),
                TextColumn::make('active')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Đang bật' : 'Đã tắt')
                    ->color(fn (bool $state): string => $state ? 'success' : 'danger')
                    ->tooltip(fn (WebhookEndpoint $record): ?string => $record->disabled_reason),
                TextColumn::make('last_success_at')
                    ->label('Gửi được lần cuối')
                    ->since()
                    ->dateTimeTooltip('H:i:s, d/m/Y')
                    ->placeholder('Chưa có'),
                TextColumn::make('consecutive_failures')
                    ->label('Hỏng liên tiếp')
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
            ])
            ->recordActions([
                ViewAction::make()->label('Xem'),
            ])
            ->emptyStateHeading('Chưa có endpoint nào')
            ->emptyStateDescription('Thêm URL trên hệ thống của shop để nhận webhook khi đơn được thanh toán, hết hạn hay bị hủy.');
    }

    public static function getRelations(): array
    {
        return [
            DeliveriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEndpoints::route('/'),
            'view' => ViewWebhookEndpoint::route('/{record}'),
        ];
    }
}
