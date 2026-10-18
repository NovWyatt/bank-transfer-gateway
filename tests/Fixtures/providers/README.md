# Fixture webhook của nhà cung cấp

Chép nguyên văn từ ví dụ trong tài liệu chính thức (tra ngày 18/10/2026). Không sửa tay: test ký HMAC trên đúng các byte này.

| File | Nguồn |
|---|---|
| `sepay/webhook.json` | https://developer.sepay.vn/en/sepay-webhooks/tich-hop-webhook (mục Payload) |
| `pay2s/webhook.json` | https://developer.pay2s.vn/docs/webhook/tai-lieu-ky-thuat/ (có `paymentCode` và `extraData`) |
| `pay2s/webhook-extra-na.json` | Cùng trang, ví dụ "Bật extraData nhưng ngân hàng không cung cấp thông tin" |
| `pay2s/webhook-va.json` | Cùng trang, ví dụ "Tài khoản ảo và mã tham chiếu đối tác" |

Các biến thể (tiền ra, thiếu trường, sai ngày...) được dựng trong test từ các file này.
