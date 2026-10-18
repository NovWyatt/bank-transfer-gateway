<?php

namespace App\Actions\Members;

use App\Models\ShopInvitation;

/**
 * Lời mời vừa tạo kèm token gốc. Token chỉ có ở đây, một lần: bảng chỉ lưu sha256.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public ShopInvitation $invitation,
        public string $token,
    ) {}

    public function url(): string
    {
        return route('filament.app.invitations.accept', ['token' => $this->token]);
    }
}
