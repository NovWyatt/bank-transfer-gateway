# Nhận webhook từ bank-transfer-gateway

Mỗi khi đơn đổi trạng thái, cổng gửi `POST` JSON có chữ ký tới URL bạn khai trên dashboard (mục **Webhook gửi đi**).
Thư mục này có hàm kiểm chữ ký và một nơi nhận mẫu, viết bằng PHP và Node, không cần thư viện ngoài.

| File | Dùng để |
|---|---|
| `php/verify-signature.php` | Hàm `gateway_verify_signature()`, PHP 7.4 trở lên |
| `php/receiver.php` | Nơi nhận mẫu, chạy bằng `php -S` |
| `node/verify-signature.mjs` | Hàm `verifyGatewaySignature()`, Node 18 trở lên |
| `node/receiver.mjs` | Nơi nhận mẫu, `node:http` thuần |

Bộ test của dự án chạy thật cả hai nơi nhận mẫu và gửi webhook thật tới (`tests/Feature/Webhooks/ExampleReceiversTest.php`).

## Header

```
Content-Type: application/json
X-Gateway-Event-Id: evt_01M57CNP5ASFYAMTGAQJFJHVM0
X-Gateway-Event-Type: order.paid
X-Gateway-Signature: t=1760752800,v1=5257a869e7ecebeda32affa62cdca3fa51cad7e77a0e56ff536d0ce8e108d8bd
```

## Kiểm chữ ký

1. Đọc **body gốc** (`php://input`, `express.raw()`). Đừng parse JSON rồi stringify lại: lệch một dấu cách là chữ ký sai.
2. Tách `t` và `v1` khỏi `X-Gateway-Signature`.
3. Tính `hex(hmac_sha256(secret, "{t}.{body gốc}"))`. Khóa là nguyên chuỗi secret, kể cả tiền tố `whsec_`.
4. So với `v1` bằng hàm so sánh hằng thời gian (`hash_equals`, `timingSafeEqual`).
5. Từ chối nếu `t` lệch giờ hiện tại quá 5 phút: chặn kẻ gian phát lại một webhook cũ.

```php
require 'verify-signature.php';

$payload = file_get_contents('php://input');

if (! gateway_verify_signature($payload, $_SERVER['HTTP_X_GATEWAY_SIGNATURE'] ?? '', getenv('GATEWAY_WEBHOOK_SECRET'))) {
    http_response_code(400);
    exit;
}
```

Secret hiện **một lần** lúc tạo endpoint. Lỡ mất thì vào endpoint, bấm **Đổi secret**: secret cũ hết hiệu lực ngay.

## Sự kiện

| `type` | Khi nào | `data.order` | `data.transaction` |
|---|---|---|---|
| `order.paid` | Đơn nhận đủ tiền | có | giao dịch vừa khớp |
| `order.partially_paid` | Khách chuyển thiếu | có | giao dịch vừa khớp |
| `order.overpaid` | Khách chuyển dư | có | giao dịch vừa khớp |
| `order.expired` | Đơn quá hạn mà chưa nhận đồng nào | có | `null` |
| `order.cancelled` | Shop hủy đơn (dashboard hoặc API) | có | `null` |
| `transaction.unmatched` | Tiền vào nhưng không khớp đơn nào | `null` | có |
| `webhook.ping` | Bấm "Gửi thử" hoặc gọi `POST /api/v1/webhook-endpoints/{id}/test` | không có | không có |

`data.order` và `data.transaction` có đúng các trường như `GET /api/v1/orders/{code}` và `GET /api/v1/transactions`
(xem `/docs/api`). Tiền là số nguyên VND, giờ là ISO 8601 theo UTC.

```json
{
  "id": "evt_01M57CNP5ASFYAMTGAQJFJHVM0",
  "type": "order.paid",
  "created_at": "2026-10-18T11:34:39Z",
  "data": {
    "order": { "code": "DHHRPRAK", "status": "paid", "amount": 320000, "paid_amount": 320000, "external_ref": "WOO-4821", "…": "…" },
    "transaction": { "id": 41, "amount": 320000, "content": "MBVCB.1234567.DHHRPRAK.CT tu NGUYEN VAN B", "order_code": "DHHRPRAK", "…": "…" }
  }
}
```

## Trả lời thế nào

- Trả **2xx trong vòng 10 giây** là xong. Việc nặng (gửi email, gọi kho) thì đẩy vào hàng đợi của bạn rồi trả 200 ngay.
- 3xx, 4xx, 5xx, quá giờ hay lỗi kết nối đều tính là hỏng. Cổng **không** đi theo chuyển hướng.
- Hỏng thì gửi lại sau 1, 5, 15, 60, 180, 360, 720 phút (8 lần trong khoảng 22 giờ), rồi dừng ở "thất bại".
  Trên dashboard có nhật ký từng lần gửi và nút **Gửi lại**.
- Một sự kiện có thể đến **nhiều lần**. Lưu `id` đã xử lý (khóa `UNIQUE`) và bỏ qua lần trùng, nhưng vẫn trả 2xx.
- Thứ tự đến không bảo đảm. Muốn trạng thái mới nhất thì gọi `GET /api/v1/orders/{code}`.
- Endpoint hỏng liên tiếp từ 20 lần và kéo dài từ 24 giờ thì tự tắt. Sửa xong, vào dashboard bấm **Bật lại**.

## Thử ở máy

```bash
GATEWAY_WEBHOOK_SECRET=whsec_... php -S 127.0.0.1:9000 examples/webhooks/php/receiver.php
```

Cổng chỉ gửi tới `https` trên địa chỉ công khai. Muốn trỏ endpoint về `http://localhost:9000` khi phát triển, đặt
`WEBHOOKS_ALLOW_PRIVATE_TARGETS=true` trong `.env` ở máy (production luôn chặn dù có đặt).
Sau đó tạo đơn, chạy `php artisan pay:simulate <mã đơn>`, rồi `php artisan webhooks:deliver` để gửi ngay.
