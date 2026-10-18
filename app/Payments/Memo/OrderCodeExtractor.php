<?php

namespace App\Payments\Memo;

use App\Payments\OrderCode;

/**
 * Tìm các mã đơn có thể có trong nội dung chuyển khoản.
 *
 * Trả về ứng viên theo thứ tự ưu tiên: mã nhà cung cấp đã tách sẵn, mã đứng thành từ riêng,
 * rồi mã dính liền với chữ khác (ngân hàng hay chèn tiền tố, hậu tố vào nội dung).
 * Ứng viên chưa chắc là đơn thật: bộ khớp phải tra trong DB của đúng shop.
 */
final class OrderCodeExtractor
{
    /**
     * @param  list<string>  $prefixes  tiền tố mã đơn của shop
     * @return list<string>
     */
    public static function candidates(?string $memo, array $prefixes, ?string $providerCode = null): array
    {
        $found = [];
        $patterns = array_map(OrderCode::pattern(...), $prefixes);

        $provided = str_replace(' ', '', MemoNormalizer::normalize($providerCode));
        foreach ($patterns as $pattern) {
            if ($provided !== '' && preg_match('/^'.$pattern.'$/', $provided) === 1) {
                $found[] = $provided;
            }
        }

        $normalized = MemoNormalizer::normalize($memo);
        if ($normalized === '') {
            return array_values(array_unique($found));
        }

        foreach (explode(' ', $normalized) as $token) {
            foreach ($patterns as $pattern) {
                if (preg_match('/^'.$pattern.'$/', $token) === 1) {
                    $found[] = $token;
                }
            }
        }

        // Tìm chồng lấn trên chuỗi đã bỏ dấu cách, bắt cả "MBVCB123456DH7K3QXCT" lẫn "DH 7K3QX".
        $compact = str_replace(' ', '', $normalized);
        foreach ($patterns as $pattern) {
            preg_match_all('/(?=('.$pattern.'))/', $compact, $matches);
            array_push($found, ...$matches[1]);
        }

        return array_values(array_unique($found));
    }
}
