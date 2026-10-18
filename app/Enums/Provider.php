<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Provider: string implements HasLabel
{
    case SePay = 'sepay';
    case Pay2S = 'pay2s';
    case PayOS = 'payos';

    /** Giả lập cho demo và test: ký HMAC như SePay, đi đúng đường nhận thật. */
    case Simulator = 'simulator';

    public function label(): string
    {
        return match ($this) {
            self::SePay => 'SePay',
            self::Pay2S => 'Pay2S',
            self::PayOS => 'payOS',
            self::Simulator => 'Giả lập',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * Khóa trong cột credentials mà adapter đọc. Rỗng nghĩa là chưa có adapter.
     *
     * @return array<string, string> khóa => nhãn trên form
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::SePay => ['secret' => 'Khóa ký HMAC', 'api_key' => 'API key'],
            self::Pay2S => ['token' => 'Token'],
            self::Simulator => ['secret' => 'Khóa ký HMAC'],
            self::PayOS => [],
        };
    }
}
