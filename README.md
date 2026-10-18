# bank-transfer-gateway

[![CI](https://github.com/NovWyatt/bank-transfer-gateway/actions/workflows/ci.yml/badge.svg)](https://github.com/NovWyatt/bank-transfer-gateway/actions/workflows/ci.yml)

Bank-transfer auto-confirmation for small shops: one API and signed webhooks on top of SePay and Pay2S,
VietQR codes, multi-shop roles and a self-learning matcher for mistyped transfer memos.

> **Trạng thái:** đang xây dựng. Xong giai đoạn 1 (nhận webhook, khớp đơn, trang thanh toán, dashboard)
> và giai đoạn 2 (API v1, webhook gửi đi có chữ ký).
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

## API v1

Owner tạo token ở dashboard, mục **Token API**. Token gắn với shop, chỉ hiện một lần, có quyền riêng
(`orders:read`, `orders:write`, `transactions:read`, `webhooks:write`). Tài liệu OpenAPI sinh tự động ở `/docs/api`.

```bash
curl -X POST http://localhost:8000/api/v1/orders \
  -H "Authorization: Bearer $GATEWAY_API_TOKEN" \
  -H "Idempotency-Key: woo-4821" \
  -H "Content-Type: application/json" \
  -d '{"amount": 320000, "external_ref": "WOO-4821", "description": "Bình gốm men lam"}'
```

Phản hồi có `pay_url` (trang thanh toán gửi cho khách), `qr_string` (chuỗi VietQR) và thông tin chuyển khoản.
Gửi lại cùng `Idempotency-Key` và cùng body trong 24 giờ thì nhận lại đúng phản hồi cũ, không tạo đơn thứ hai.
Lỗi luôn có dạng `{"message": "...", "code": "..."}`.

## Webhook gửi tới shop

Owner khai URL ở mục **Webhook gửi đi**. Mỗi khi đơn đã thanh toán, trả thiếu, trả dư, hết hạn, bị hủy, hoặc có tiền
vào mà không khớp đơn nào, cổng gửi `POST` JSON có header `X-Gateway-Signature: t={unix},v1={hmac_sha256}`.
Hàm kiểm chữ ký và nơi nhận mẫu (PHP, Node) ở [`examples/webhooks`](examples/webhooks/README.md).

Hỏng thì gửi lại sau 1, 5, 15, 60, 180, 360, 720 phút. Cổng chỉ gửi tới `https` trên địa chỉ IP công khai,
phân giải lại DNS ở mỗi lần gửi và ghim IP đã kiểm, để URL webhook không bị dùng để dò mạng nội bộ (SSRF).

### Thư viện

| Thư viện | Dùng cho |
|---|---|
| `laravel/framework` 13 | Khung ứng dụng |
| `pestphp/pest` 4, `pestphp/pest-plugin-laravel` | Test |
| `larastan/larastan` 3 | Phân tích tĩnh |
| `laravel/pint` | Định dạng mã |
| `filament/filament` 5 (kèm Livewire 4) | Dashboard của shop, tách dữ liệu theo shop (tenancy) |
| `chillerlan/php-qrcode` 5 | Vẽ mã VietQR ra SVG, không cần GD |
| `laravel/sanctum` 4 | Token API gắn với shop, có quyền theo token |
| `dedoc/scramble` 0.13 | Sinh tài liệu OpenAPI cho API v1 ở `/docs/api` |
| Phông Hanken Grotesk (SIL OFL 1.1) | Chữ của trang thanh toán và dashboard, tự host ở `public/fonts/hanken-grotesk` |

## Giấy phép

MIT
