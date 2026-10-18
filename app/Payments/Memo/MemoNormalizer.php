<?php

namespace App\Payments\Memo;

use Illuminate\Support\Str;

/**
 * Chuẩn hóa nội dung chuyển khoản do khách gõ: bỏ dấu tiếng Việt, đổi chữ hoa,
 * thay mọi ký tự không phải chữ hoặc số bằng một dấu cách.
 *
 * Nội dung này không đáng tin: chỉ dùng để tìm mã và tính đặc trưng, luôn escape khi hiển thị.
 */
final class MemoNormalizer
{
    public static function normalize(?string $memo): string
    {
        if ($memo === null || $memo === '') {
            return '';
        }

        $upper = strtoupper(Str::ascii($memo, 'vi'));

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', $upper));
    }
}
