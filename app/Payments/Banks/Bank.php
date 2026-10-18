<?php

namespace App\Payments\Banks;

final readonly class Bank
{
    public function __construct(
        /** Mã BIN 6 chữ số của NAPAS, dùng trong chuỗi VietQR. */
        public string $bin,
        /** Mã viết tắt, ví dụ "VCB". SePay gửi trường này trong gateway. */
        public string $code,
        public string $shortName,
        public string $name,
        public bool $transferSupported,
    ) {}
}
