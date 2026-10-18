<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WebhookDeliveryStatus: string implements HasColor, HasLabel
{
    /** Chờ lần gửi kế tiếp (next_attempt_at luôn có giá trị). */
    case Pending = 'pending';

    case Succeeded = 'succeeded';

    /** Hết lượt gửi lại, hoặc lần gửi tay đầu tiên đã hỏng. */
    case Failed = 'failed';

    /** Đến lượt gửi thì endpoint đã tắt. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Chờ gửi',
            self::Succeeded => 'Thành công',
            self::Failed => 'Thất bại',
            self::Skipped => 'Bỏ qua',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Skipped => 'gray',
        };
    }
}
