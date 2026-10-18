<?php

namespace App\Filament\Resources\WebhookEndpoints\RelationManagers;

use App\Enums\DeliveryTrigger;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Filament\Resources\WebhookEndpoints\Pages\ViewWebhookEndpoint;
use App\Models\WebhookAttempt;
use App\Models\WebhookDelivery;
use App\Webhooks\WebhookSender;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Attributes\On;

/**
 * Nhật ký gửi của một endpoint: mỗi dòng là một sự kiện, bấm "Xem" để thấy từng lần gửi và payload.
 */
class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Nhật ký gửi';

    /** Trang cha vừa gửi thử: vẽ lại bảng để thấy dòng mới. */
    #[On('webhook-deliveries-changed')]
    public function refreshDeliveries(): void {}

    public function isReadOnly(): bool
    {
        return false;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('event_type')->label('Loại')->badge()->color('gray'),
                        TextEntry::make('status')->label('Trạng thái')->badge(),
                        TextEntry::make('event_id')->label('Event id')->copyable(),
                    ]),
                Section::make('Các lần gửi')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('attempt_log')
                            ->hiddenLabel()
                            // Truy vấn tường minh thay vì đọc quan hệ: model chặn lazy load ngoài production.
                            ->state(fn (WebhookDelivery $record) => $record->attempts()->latest('id')->get())
                            ->placeholder('Chưa gửi lần nào.')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('created_at')->label('Lúc')->dateTime('H:i:s, d/m/Y'),
                                TextEntry::make('trigger')->label('Kiểu'),
                                TextEntry::make('response_code')
                                    ->label('Kết quả')
                                    ->badge()
                                    ->state(fn (WebhookAttempt $record): string => $record->response_code === null
                                        ? 'Không có phản hồi'
                                        : "HTTP {$record->response_code}")
                                    ->color(fn (WebhookAttempt $record): string => $record->succeeded() ? 'success' : 'danger'),
                                TextEntry::make('duration_ms')->label('Thời gian')->suffix(' ms'),
                                TextEntry::make('error')
                                    ->label('Lỗi')
                                    ->columnSpanFull()
                                    ->hidden(fn (WebhookAttempt $record): bool => $record->error === null),
                                TextEntry::make('response_body')
                                    ->label('Phản hồi (2 KB đầu)')
                                    ->columnSpanFull()
                                    ->fontFamily(FontFamily::Mono)
                                    ->hidden(fn (WebhookAttempt $record): bool => $record->response_body === null),
                            ]),
                    ]),
                Section::make('Payload đã ký')
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('payload')
                            ->hiddenLabel()
                            ->fontFamily(FontFamily::Mono)
                            // Nội dung chuyển khoản do khách gõ nằm trong payload: e() trước khi đưa vào <pre>.
                            ->formatStateUsing(fn (string $state): string => '<pre class="overflow-x-auto text-xs">'
                                .e((string) json_encode(json_decode($state), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                                .'</pre>')
                            ->html(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // Lần gửi tự động chạy mỗi phút; bảng tự cập nhật để thấy trạng thái đổi.
            ->poll('15s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Sự kiện lúc')
                    ->dateTime('H:i:s, d/m'),
                TextColumn::make('event_type')
                    ->label('Loại')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('attempt_count')
                    ->label('Lần gửi tự động'),
                TextColumn::make('last_response_code')
                    ->label('Mã HTTP')
                    ->placeholder('Không có'),
                TextColumn::make('next_attempt_at')
                    ->label('Gửi lại lúc')
                    ->dateTime('H:i, d/m')
                    ->placeholder('Không'),
                TextColumn::make('event_id')
                    ->label('Event id')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(WebhookDeliveryStatus::class),
                SelectFilter::make('event_type')
                    ->label('Loại')
                    ->options(WebhookEventType::class),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Xem')
                    ->modalHeading(fn (WebhookDelivery $record): string => "Sự kiện {$record->event_type->value}"),
                Action::make('resend')
                    ->label('Gửi lại')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action(function (WebhookDelivery $record, WebhookSender $sender): void {
                        ViewWebhookEndpoint::notifyAttempt($sender->send($record, DeliveryTrigger::Manual), 'Gửi lại');
                    }),
            ])
            ->emptyStateHeading('Chưa gửi sự kiện nào')
            ->emptyStateDescription('Bấm "Gửi thử" ở trên để kiểm tra endpoint nhận và kiểm chữ ký đúng.');
    }
}
