# bank-transfer-gateway

[![CI](https://github.com/NovWyatt/bank-transfer-gateway/actions/workflows/ci.yml/badge.svg)](https://github.com/NovWyatt/bank-transfer-gateway/actions/workflows/ci.yml)

Bank-transfer auto-confirmation for small shops: one API and signed webhooks on top of SePay and Pay2S,
VietQR codes, multi-shop roles and a self-learning matcher for mistyped transfer memos.

> **Trạng thái:** đang xây dựng, giai đoạn 0 (khung dự án, CI, thử hạ tầng).
> Kế hoạch đầy đủ: [`docs/KE-HOACH.md`](docs/KE-HOACH.md).

## Dự án làm gì

Shop nhỏ ở Việt Nam thường nhận chuyển khoản, rồi mở app ngân hàng để soát xem khách đã trả chưa.
Cổng này làm việc đó tự động:

- Mỗi đơn có một mã ngắn và một mã VietQR đã điền sẵn số tiền và nội dung.
- Tiền vào thẳng tài khoản ngân hàng của shop. App không giữ tiền.
- Khi SePay hoặc Pay2S báo có biến động số dư, app đọc mã đơn trong nội dung chuyển khoản và tự chuyển đơn sang **đã thanh toán**.
- Sau đó app báo cho shop qua Telegram, và gửi webhook có chữ ký HMAC tới hệ thống của shop.

## Cài đặt ở máy

Cần PHP 8.3, Composer, MySQL hoặc MariaDB, Node 22+.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
php artisan serve
```

Kiểm tra chất lượng (Pint, Larastan level 6, Pest):

```bash
composer check
```

Test Feature chạy trên MySQL thật, database `bank_transfer_gateway_test` (xem `phpunit.xml`).

### Thư viện

| Thư viện | Dùng cho |
|---|---|
| `laravel/framework` 13 | Khung ứng dụng |
| `pestphp/pest` 4, `pestphp/pest-plugin-laravel` | Test |
| `larastan/larastan` 3 | Phân tích tĩnh |
| `laravel/pint` | Định dạng mã |

## Giấy phép

MIT
