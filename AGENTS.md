# bank-transfer-gateway

Cổng nhận chuyển khoản tự xác nhận cho shop nhỏ: Laravel 13, PHP 8.3.
Kế hoạch và trạng thái nằm ở `docs/KE-HOACH.md`. Đọc trước khi làm. Cuối mỗi giai đoạn, cập nhật phần đã xong, còn lại và bẫy gặp phải.

## Lệnh

- PHP: `C:/Users/Wyatt/Larabox/bin/php/8.3.33/php.exe`. Các shim `php.cmd` và `composer.cmd` đi qua cmd nên làm mất ký tự `^`. Gọi thẳng `php.exe`, với Composer thì gọi `php.exe C:/Users/Wyatt/Larabox/bin/composer.phar`.
- `composer check`: chạy Pint `--test`, Larastan level 6 và Pest.
- Test Feature chạy trên MariaDB thật (database `bank_transfer_gateway_test`), vì khớp đơn dựa vào `lockForUpdate` và `UNIQUE`.
- Chạy app ở máy: `php artisan serve` (`http://localhost:8000`).

## Quy ước

- Tiền là số nguyên VND, không dùng float.
- Commit nhỏ, viết tiếng Việt, dưới tên `NovWyatt <rirtar.lofi@gmail.com>`. Hook quét bí mật: `git config core.hooksPath .githooks`.
- Giao tiếp tiếng Việt có dấu.
- Các quy tắc làm việc (dữ liệu phiên khác, GitHub, Cloudflare chỉ dùng tài khoản rirtar.lofi, giao diện theo taste-skill) nằm ở mục 0 của kế hoạch.
