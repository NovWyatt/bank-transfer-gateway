# bank-transfer-gateway

[![CI](https://github.com/NovWyatt/bank-transfer-gateway/actions/workflows/ci.yml/badge.svg)](https://github.com/NovWyatt/bank-transfer-gateway/actions/workflows/ci.yml)

Bank-transfer auto-confirmation for small shops: one API and signed webhooks on top of SePay and Pay2S,
VietQR codes, multi-shop roles and a self-learning matcher for mistyped transfer memos.

> **Trạng thái:** đang xây dựng, giai đoạn 1 (lõi: nhận webhook, khớp đơn, trang thanh toán, dashboard).
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
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Seeder dựng shop mẫu "Tiệm gốm Nhà Mây" với sáu đơn ở đủ các trạng thái và một kết nối giả lập.
Dashboard ở `http://localhost:8000/app`, tài khoản đăng nhập ghi trong `database/seeders/LocalShopSeeder.php`.
Giả lập khách chuyển khoản (đi đúng đường nhận webhook thật, có ký HMAC):

```bash
php artisan pay:simulate DH7K3QXM 150000
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
| `filament/filament` 5 (kèm Livewire 4) | Dashboard của shop, tách dữ liệu theo shop (tenancy) |
| `chillerlan/php-qrcode` 5 | Vẽ mã VietQR ra SVG, không cần GD |
| Phông Hanken Grotesk (SIL OFL 1.1) | Chữ của trang thanh toán và dashboard, tự host ở `public/fonts/hanken-grotesk` |

## Giấy phép

MIT
