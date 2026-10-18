<?php

namespace App\Filament\Resources\WebhookEndpoints\Pages;

use App\Filament\Concerns\RevealsSecretOnce;
use App\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListWebhookEndpoints extends ListRecords
{
    use RevealsSecretOnce;

    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Không dùng CreateAction: tạo xong phải mở ngay hộp hiện secret một lần.
            Action::make('createEndpoint')
                ->label('Thêm endpoint')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Thêm endpoint nhận webhook')
                ->schema(WebhookEndpointResource::formFields())
                ->modalSubmitActionLabel('Thêm')
                ->action(function (array $data): void {
                    /** @var Shop $shop */
                    $shop = Filament::getTenant();
                    /** @var User $user */
                    $user = Filament::auth()->user();

                    $endpoint = new WebhookEndpoint($data);
                    $endpoint->shop_id = $shop->getKey();
                    $endpoint->save();

                    AuditLog::record($shop->getKey(), $user, 'webhook_endpoint.created', $endpoint, [
                        'url' => $endpoint->url,
                    ], request()->ip());

                    $this->revealSecret(
                        heading: 'Secret của endpoint',
                        description: 'Dùng secret này để kiểm header X-Gateway-Signature (script mẫu ở examples/webhooks). '
                            .'App lưu bản mã hóa và không hiện lại: lỡ mất thì vào endpoint, bấm "Đổi secret".',
                        secret: (string) $endpoint->secret,
                    );
                }),
        ];
    }
}
