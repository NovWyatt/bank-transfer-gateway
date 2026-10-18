<?php

namespace App\Rules;

use App\Webhooks\Security\UnsafeUrl;
use App\Webhooks\Security\UrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * URL nhận webhook phải qua được bộ chặn SSRF ngay lúc lưu, để owner thấy lỗi sớm.
 * Lúc gửi vẫn kiểm lại, vì DNS có thể đổi sau khi lưu.
 */
final class WebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('URL không hợp lệ.');

            return;
        }

        try {
            app(UrlGuard::class)->check($value);
        } catch (UnsafeUrl $e) {
            $fail($e->getMessage());
        }
    }
}
