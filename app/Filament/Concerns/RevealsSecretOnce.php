<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

/**
 * Hộp hiện một bí mật vừa tạo (token API, secret webhook) đúng một lần.
 * App chỉ lưu bản băm hoặc bản mã hóa: đóng hộp là không xem lại được.
 */
trait RevealsSecretOnce
{
    public function revealSecretAction(): Action
    {
        return Action::make('revealSecret')
            ->modalHeading(fn (array $arguments): string => $arguments['heading'] ?? '')
            ->modalDescription(fn (array $arguments): string => $arguments['description'] ?? '')
            ->schema([
                TextInput::make('secret')
                    ->label('Chỉ hiện lúc này')
                    ->readOnly()
                    ->copyable(copyMessage: 'Đã chép'),
            ])
            ->fillForm(fn (array $arguments): array => ['secret' => $arguments['secret'] ?? ''])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đã chép, đóng lại');
    }

    protected function revealSecret(string $heading, string $description, string $secret): void
    {
        $this->replaceMountedAction('revealSecret', [
            'heading' => $heading,
            'description' => $description,
            'secret' => $secret,
        ]);
    }
}
