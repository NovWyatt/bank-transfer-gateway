<?php

namespace App\Filament\Resources\WebhookEndpoints\Pages;

use App\Enums\DeliveryTrigger;
use App\Filament\Concerns\RevealsSecretOnce;
use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebhookAttempt;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookDispatcher;
use App\Webhooks\WebhookSender;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property WebhookEndpoint $record
 */
class ViewWebhookEndpoint extends ViewRecord
{
    use RevealsSecretOnce;

    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTest')
                ->label('Gửi thử')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->action(function (WebhookDispatcher $dispatcher, WebhookSender $sender): void {
                    $attempt = $sender->send($dispatcher->ping($this->record), DeliveryTrigger::Manual);

                    self::notifyAttempt($attempt, 'Gửi thử');
                    $this->refreshRecord();
                }),
            EditAction::make()
                ->label('Sửa')
                ->after(fn (WebhookEndpoint $record) => $this->audit('webhook_endpoint.updated', ['url' => $record->url])),
            ActionGroup::make([
                Action::make('rotateSecret')
                    ->label('Đổi secret')
                    ->icon(Heroicon::OutlinedKey)
                    ->requiresConfirmation()
                    ->modalHeading('Đổi secret?')
                    ->modalDescription('Secret cũ hết hiệu lực ngay: webhook gửi từ lúc này ký bằng secret mới. '
                        .'Cập nhật secret bên hệ thống của bạn ngay sau khi đổi.')
                    ->modalSubmitActionLabel('Đổi secret')
                    ->action(function (): void {
                        $secret = $this->record->rotateSecret();
                        $this->audit('webhook_endpoint.secret_rotated');

                        $this->revealSecret(
                            heading: 'Secret mới của endpoint',
                            description: 'Thay secret cũ bên hệ thống của bạn bằng chuỗi này. App không hiện lại secret sau khi đóng hộp.',
                            secret: $secret,
                        );
                    }),
                Action::make('enable')
                    ->label('Bật lại')
                    ->icon(Heroicon::OutlinedPlay)
                    ->visible(fn (): bool => ! $this->record->active)
                    ->action(function (): void {
                        $this->record->enable();
                        $this->audit('webhook_endpoint.enabled');

                        Notification::make()->success()
                            ->title('Đã bật lại endpoint.')
                            ->body('Sự kiện bị bỏ qua lúc endpoint tắt không tự gửi lại: bấm "Gửi lại" ở nhật ký nếu cần.')
                            ->send();
                    }),
                Action::make('disable')
                    ->label('Tạm tắt')
                    ->icon(Heroicon::OutlinedPause)
                    ->color('warning')
                    ->visible(fn (): bool => $this->record->active)
                    ->requiresConfirmation()
                    ->modalHeading('Tạm tắt endpoint?')
                    ->modalDescription('Sự kiện mới không gửi tới endpoint này. Các lần gửi lại đang chờ sẽ bị bỏ qua.')
                    ->modalSubmitActionLabel('Tạm tắt')
                    ->action(function (): void {
                        $this->record->forceFill([
                            'active' => false,
                            'disabled_at' => now(),
                            'disabled_reason' => 'Chủ shop tạm tắt.',
                        ])->save();
                        $this->audit('webhook_endpoint.disabled');

                        Notification::make()->success()->title('Đã tạm tắt endpoint.')->send();
                    }),
                DeleteAction::make()
                    ->label('Xóa')
                    ->modalDescription('Xóa cả nhật ký gửi của endpoint này. Không hoàn tác được.')
                    ->before(fn () => $this->audit('webhook_endpoint.deleted', ['url' => $this->record->url])),
            ]),
        ];
    }

    /** Thông báo kết quả một lần gửi tay, dùng chung cho "Gửi thử" và "Gửi lại". */
    public static function notifyAttempt(WebhookAttempt $attempt, string $what): void
    {
        if ($attempt->succeeded()) {
            Notification::make()->success()
                ->title("{$what}: endpoint trả HTTP {$attempt->response_code} sau {$attempt->duration_ms} ms.")
                ->send();

            return;
        }

        Notification::make()->danger()
            ->title("{$what} không thành công.")
            ->body($attempt->response_code !== null
                ? "Endpoint trả HTTP {$attempt->response_code}. Chỉ 2xx mới tính là nhận được."
                : $attempt->error)
            ->send();
    }

    private function refreshRecord(): void
    {
        $this->record->refresh();
        $this->dispatch('webhook-deliveries-changed');
    }

    /** @param  array<string, mixed>|null  $meta */
    private function audit(string $action, ?array $meta = null): void
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        AuditLog::record($this->record->shop_id, $user, $action, $this->record, $meta, request()->ip());
    }
}
