<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramError;
use App\Telegram\TelegramLinkCodes;
use App\Tenancy\ShopAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Nối chat Telegram của chủ shop để nhận tin tiền về. Chỉ owner.
 * Nối bằng link t.me/<bot>?start=<mã một lần>: không phải chép chat_id bằng tay.
 */
class TelegramNotifications extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Thông báo Telegram';

    protected static ?string $title = 'Thông báo Telegram';

    protected static ?string $slug = 'telegram';

    public static function canAccess(): bool
    {
        return ShopAccess::isOwner();
    }

    public function content(Schema $schema): Schema
    {
        $telegram = app(TelegramClient::class);

        if (! $telegram->configured() || $telegram->username() === null) {
            return $schema->components([
                Callout::make('Bot Telegram chưa được cấu hình')
                    ->description('Người quản trị app cần đặt TELEGRAM_BOT_TOKEN và TELEGRAM_BOT_USERNAME, rồi deploy lại.')
                    ->warning(),
            ]);
        }

        $connected = $this->shop()->telegram_chat_id !== null;

        return $schema->components([
            Callout::make($connected ? 'Đã nối Telegram' : 'Chưa nối Telegram')
                ->description($connected
                    ? 'Mỗi khoản tiền vào tài khoản của shop sẽ báo vào chat đã nối, kèm mã đơn nếu khớp được. Webhook tự tắt cũng báo ở đây.'
                    : 'Bấm "Nối Telegram", mở link trong Telegram rồi bấm Start. Nối vào nhóm thì thêm bot vào nhóm trước, rồi gửi lệnh /start kèm mã trong nhóm.')
                ->status($connected ? 'success' : 'info'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $ready = app(TelegramClient::class)->configured() && app(TelegramClient::class)->username() !== null;
        $connected = $this->shop()->telegram_chat_id !== null;

        return [
            Action::make('connect')
                ->label($connected ? 'Nối chat khác' : 'Nối Telegram')
                ->icon(Heroicon::OutlinedLink)
                ->color($connected ? 'gray' : 'primary')
                ->visible($ready)
                ->action(function (TelegramLinkCodes $codes, TelegramClient $telegram): void {
                    $code = $codes->issue($this->shop());

                    $this->replaceMountedAction('telegramLink', [
                        'url' => 'https://t.me/'.$telegram->username().'?start='.$code,
                        'command' => '/start@'.$telegram->username().' '.$code,
                    ]);
                }),
            Action::make('sendTest')
                ->label('Gửi tin thử')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->visible($ready && $connected)
                ->action(function (TelegramClient $telegram): void {
                    $shop = $this->shop();

                    // Gửi ngay, không qua hàng đợi: owner cần biết kết quả lúc bấm.
                    try {
                        $telegram->sendMessage((string) $shop->telegram_chat_id, "Tin thử từ Bank Transfer Gateway cho shop {$shop->name}. Tiền về sẽ báo ở đây.");
                    } catch (TelegramError $e) {
                        Notification::make()->danger()->title('Gửi không được')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('Đã gửi, xem trong Telegram.')->send();
                }),
            Action::make('disconnect')
                ->label('Ngắt')
                ->icon(Heroicon::OutlinedLinkSlash)
                ->color('danger')
                ->visible($connected)
                ->requiresConfirmation()
                ->modalHeading('Ngắt Telegram?')
                ->modalDescription('Tiền về sẽ không báo vào Telegram nữa. Nối lại lúc nào cũng được.')
                ->modalSubmitActionLabel('Ngắt')
                ->action(function (): void {
                    $shop = $this->shop();
                    $shop->forceFill(['telegram_chat_id' => null])->save();

                    AuditLog::record($shop->getKey(), $this->user(), 'telegram.disconnected', $shop, ['reason' => 'owner ngắt ở dashboard'], request()->ip());

                    Notification::make()->success()->title('Đã ngắt Telegram.')->send();
                }),
        ];
    }

    public function telegramLinkAction(): Action
    {
        return Action::make('telegramLink')
            ->modalHeading('Mở link này trong Telegram')
            ->modalDescription('Link dùng một lần, hết hạn sau '.TelegramLinkCodes::TTL_MINUTES.' phút. Mở xong bấm Start; bot trả lời "Đã nối" là xong, tải lại trang này để thấy.')
            ->modalContent(fn (array $arguments): HtmlString => new HtmlString(
                '<a href="'.e($arguments['url'] ?? '').'" target="_blank" rel="noopener" class="btg-telegram-open">Mở Telegram</a>',
            ))
            ->schema([
                TextInput::make('command')
                    ->label('Nối vào nhóm: thêm bot vào nhóm, rồi gửi lệnh này trong nhóm')
                    ->readOnly()
                    ->copyable(copyMessage: 'Đã chép lệnh'),
            ])
            ->fillForm(fn (array $arguments): array => ['command' => $arguments['command'] ?? ''])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Xong');
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
