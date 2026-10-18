<?php

namespace App\Filament\Concerns;

use App\Actions\Members\IssuedInvitation;
use App\Models\ShopInvitation;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

/**
 * Hộp hiện link mời vừa tạo, để owner chép và gửi qua Zalo, Messenger.
 * Dùng chung cho trang Thành viên và bảng lời mời (mỗi nơi là một component Livewire riêng).
 */
trait ShowsInvitationLink
{
    public function invitationLinkAction(): Action
    {
        return Action::make('invitationLink')
            ->modalHeading('Gửi link này cho người được mời')
            ->modalDescription(fn (array $arguments): string => 'Link dùng một lần, chỉ cho email '.($arguments['email'] ?? '')
                .', hết hạn sau '.ShopInvitation::TTL_DAYS.' ngày. Link chỉ hiện lúc này: lỡ mất thì bấm "Tạo link mới" ở lời mời đó.')
            ->schema([
                TextInput::make('url')
                    ->label('Link mời')
                    ->readOnly()
                    ->copyable(copyMessage: 'Đã chép link'),
            ])
            ->fillForm(fn (array $arguments): array => ['url' => $arguments['url'] ?? ''])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Xong');
    }

    protected function showInvitationLink(IssuedInvitation $issued): void
    {
        $this->replaceMountedAction('invitationLink', [
            'email' => $issued->invitation->email,
            'url' => $issued->url(),
        ]);
    }
}
