# Kế hoạch: Cổng nhận chuyển khoản tự xác nhận

> Viết ngày 18/10/2026 ở phiên hồ sơ GitHub (`D:\Repo\NovWyatt`). Dự án làm ở **phiên khác**.
> Tên repo: **`bank-transfer-gateway`** (Wyatt chốt ngày 18/10/2026).
> **Cập nhật 18/10/2026 ở phiên dự án:** dự án đặt tại `C:\Users\Wyatt\Larabox\www\bank-transfer-gateway` (nơi Wyatt tạo thư mục).
> Thêm quy tắc làm việc (mục 0), giao diện (mục 9a), Cloudflare (mục 10a). Kết quả tra phiên bản thư viện ghi ở mục 9 và 16.
> Mục nào ghi **[cần kiểm tra]** là chưa xác minh được từ nguồn chính thức. Hãy kiểm tra trước khi dựa vào.

---

## 0. Cách dùng tài liệu này (dành cho phiên mới)

1. Đọc hết tài liệu một lượt, rồi làm theo các giai đoạn ở mục 12, đúng thứ tự.
2. Sau `composer create-project`, chép file này vào repo thành `docs/KE-HOACH.md`. Từ đó, sửa trạng thái ngay trong bản ở repo.
3. Giai đoạn 0 có một **spike hạ tầng**. Nếu spike thất bại thì đổi phương án theo mục 10, đừng cố viết tiếp.
4. Dự án **không gọi dịch vụ AI bên ngoài** (Wyatt quyết định ngày 18/10/2026). Phần gợi ý khớp đơn là một mô hình nhỏ tự viết, chạy ngay trong app (mục 8).
5. Không làm thay Wyatt các việc ở mục 13: đăng ký tài khoản, đăng nhập, nhập token hay khóa API, chuyển tiền.
6. **Dữ liệu phiên khác** (Wyatt dặn 18/10/2026): không đọc transcript, memory hay thư mục của phiên và dự án khác. Muốn tham khảo thì hỏi rõ Wyatt (đọc gì, để làm gì) và chờ đồng ý.
7. **GitHub** (Wyatt cho phép 18/10/2026): Claude được thao tác dưới danh nghĩa NovWyatt: tạo repo public, push, nhánh, PR, issue, biến Actions.
   - Vẫn hỏi trước khi xóa repo, force-push `main` hay đổi chế độ public/private.
   - Token và secret vẫn do Wyatt tự nhập.
8. **Cloudflare:** chỉ dùng tài khoản **rirtar.lofi@gmail.com**. Kiểm tra tài khoản trước mỗi thao tác (mục 10a).
9. **Giao diện:** dùng taste-skill để không ra kiểu template AI. Claude được tham khảo web lấy màu, phông, heading, và tự tạo hình ảnh (mục 9a).

---

## 1. Tóm tắt

Shop nhỏ ở Việt Nam thường nhận chuyển khoản rồi ngồi mở app ngân hàng để soát xem khách đã trả chưa. Dự án này là **lớp trung gian** để việc đó tự động.

- Mỗi đơn có **mã ngắn** và **mã VietQR** đã điền sẵn số tiền và nội dung.
- Tiền vào thẳng **tài khoản ngân hàng của shop**. App **không giữ tiền**.
- App nhận thông báo biến động số dư từ các dịch vụ như SePay và Pay2S, đọc mã đơn trong nội dung chuyển khoản, rồi tự chuyển đơn sang **đã thanh toán**.
- Sau đó app báo cho shop qua **Telegram** và gửi **webhook có chữ ký** tới hệ thống của shop.

Có hai nhóm người dùng:

| Nhóm | Dùng gì |
|---|---|
| Shop không biết code | Dashboard, tạo đơn hoặc link thanh toán, trang thanh toán có QR, thông báo Telegram |
| Lập trình viên | Một API để tạo đơn và lấy QR, cùng **một định dạng webhook chuẩn**, dù phía sau là SePay, Pay2S hay payOS |

**Câu mô tả cho thẻ profile** (tiếng Anh, cùng giọng với các thẻ khác):
> Bank-transfer auto-confirmation for small shops: one API and signed webhooks on top of SePay and Pay2S,
> VietQR codes, multi-shop roles and a self-learning matcher for mistyped transfer memos.

---

## 2. Định vị: dự án này khác gì SePay hay Pay2S

SePay và Pay2S đã có sẵn webhook, cổng thanh toán và plugin. Dự án này là **portfolio**, không cạnh tranh thương mại. Điểm khác:

1. **Mã nguồn mở, tự host được.**
2. **Gộp nhiều nhà cung cấp sau một API.** Shop đổi nhà cung cấp mà không phải sửa code của mình (mẫu adapter).
3. **Nhiều shop, phân quyền** (owner, staff), dữ liệu tách theo shop (multi-tenant).
4. **Webhook gửi đi có chữ ký HMAC**, retry theo lịch, có nhật ký từng lần gửi và nút gửi lại.
5. **Gợi ý khớp đơn tự học** khi khách gõ sai nội dung. Đây là một mô hình nhỏ chạy ngay trong app, học từ các lần nhân viên duyệt. Không gọi API ngoài, không tốn tiền. Luôn có **người duyệt**.
6. **Chế độ demo** cho nhà tuyển dụng bấm thử, không cần tài khoản ngân hàng.

**Không làm:** giữ tiền, chi hộ, hoàn tiền tự động, lưu thông tin đăng nhập ngân hàng, cào dữ liệu ngân hàng.

---

## 3. Luồng chính

**Shop tạo đơn:**
1. Shop tạo đơn trên dashboard hoặc qua `POST /api/v1/orders`.
2. App sinh mã đơn (ví dụ `DH7K3QXM`), chuỗi VietQR và trang `/pay/DH7K3QXM`.
3. Khách quét QR. App ngân hàng tự điền số tiền và nội dung `DH7K3QXM`.
4. Ngân hàng báo biến động số dư cho SePay hoặc Pay2S, và họ gọi webhook về app.
5. App xác thực request, lưu lại, bỏ bản trùng, tìm mã đơn rồi chuyển đơn sang `paid`.
6. Trang `/pay/...` đang hỏi trạng thái sẽ thấy "Đã thanh toán". Shop nhận tin Telegram. Hệ thống của shop nhận webhook `order.paid`.

**Khách gõ sai nội dung**, ví dụ `DH7K3OXM` hoặc chỉ ghi tên:
1. Giao dịch rơi vào hộp **Chưa khớp**.
2. App lấy các đơn có thể là đơn đó, như đơn cùng số tiền hay đơn có mã gần giống.
3. Mô hình chấm điểm từng đơn và gợi ý đơn cao điểm nhất, kèm độ tin cậy và lý do.
4. Nhân viên bấm **Duyệt** hoặc **Bỏ qua**. Hệ thống ghi nhật ký kiểm toán.

---

## 4. Nhà cung cấp dữ liệu giao dịch (tra ngày 18/10/2026)

| | **SePay** | **Pay2S** | **payOS** | **Casso** |
|---|---|---|---|---|
| Cách hoạt động | Theo dõi biến động số dư tài khoản của shop | Như SePay | Shop tạo link thanh toán qua API của payOS | Theo dõi biến động số dư |
| Gói miễn phí | FREE 0đ: 50 giao dịch/tháng, có Webhook và API, nhận cả tài khoản cá nhân | Free 0đ: 1 tài khoản, 50 giao dịch/tháng. Gói Free có tạo Hook được không: **[cần kiểm tra]** | **[cần kiểm tra]**, kể cả cá nhân có đăng ký được không | **[cần kiểm tra]** |
| Môi trường thử | Nút "Gửi thử" webhook trên dashboard | **Có sandbox** và khóa demo (không chuyển tiền thật) | **[cần kiểm tra]** | **[cần kiểm tra]** |
| Xác thực webhook | HMAC-SHA256 trên chuỗi `{timestamp}.{raw_body}`. Header `X-SePay-Signature: sha256={hex}` và `X-SePay-Timestamp` (giây unix). Lệch quá 5 phút thì từ chối. Hoặc dùng `Authorization: Apikey ...` | `Authorization: Bearer <token>`. Token chỉ hiện **một lần** khi tạo Hook. Trường `checksum` có trong payload, nhưng tài liệu không nói cách tính nên **không dựa vào** | HMAC-SHA256 (hex) trên object `data`: sắp khóa theo bảng chữ cái, ghép `k1=v1&k2=v2`, null thành chuỗi rỗng. Khóa là checksum key | **[cần kiểm tra]** |
| Dạng payload | Một giao dịch mỗi request | `{"transactions": [...]}`. Tài liệu nói mỗi request một giao dịch, nhưng vẫn **phải duyệt cả mảng** | `{code, desc, success, data, signature}` | — |
| Phản hồi xác nhận (ACK) | HTTP 200/201 và body đúng `{"success": true}`, trong 30 giây | Hai trang tài liệu nói khác nhau. Luôn trả **HTTP 200 và `{"success": true}`** là đúng với cả hai | 2xx | — |
| Retry | 8 lần gửi (1 lần đầu + 7 lần lại), giãn theo Fibonacci: sau 1, 1, 2, 3, 5, 8, 13 phút, tổng khoảng 33 phút. Retry khi không kết nối được hoặc HTTP ngoài 2xx (đã xác nhận ở trang "Error handling", 18/10/2026) | Gửi lại sau 60 giây, tối đa thêm 5 lần | **[cần kiểm tra]** | — |
| Ngân hàng | Nhiều | VCB, MB, ACB, BIDV, OCB… (danh sách đủ: **[cần kiểm tra]**) | — | — |
| Thư viện PHP | `sepayvn/laravel-sepay` v1.2.3 (Laravel 9–13). Chỉ dùng để tham khảo, tự viết adapter | SDK Node.js/PHP cho cổng thanh toán | — | — |

Các trường trong payload:
- **SePay:**
  - `id` (int, khóa chống trùng), `gateway`, `transactionDate` ("YYYY-MM-DD HH:mm:ss", giờ VN), `accountNumber`, `subAccount`
  - `code` (có thể null; SePay tự tách theo tiền tố đã cấu hình), `content`, `transferType` (`in` hoặc `out`)
  - `transferAmount` (int VND), `accumulated`, `referenceCode`, `description`
- **Pay2S:**
  - `id`, `gateway`, `transactionDate`, `transactionNumber`, `accountNumber`, `content`
  - `transferType` (`IN` hoặc `OUT`, **viết hoa**, khác SePay), `transferAmount`, `checksum`
  - Không bắt buộc: `paymentCode`, `extraData` (`remitterName`, `remitterAccountNumber`, `issuerBankName`, `reciprocalBankCode`; thiếu thì là `"N/A"`), `billNumber`, `partnerRefId`

**Ghi chú khi viết adapter (18/10/2026):**
- SePay cho chọn kiểu body JSON, urlencoded hoặc multipart. Với multipart, PHP không giữ body gốc nên không kiểm được HMAC: hướng dẫn shop chọn `application/json`. Adapter vẫn đọc được urlencoded.
- Nút "Gửi thử" của SePay gửi `id` giả, có thể là `0`, nên adapter nhận `id >= 0`.
- SePay không nhận kiểu xác thực "None": kết nối phải có `secret` (HMAC) hoặc `api_key`.
- Pay2S với tài khoản ảo gửi `accountNumber` = `963869` + số VA, không phải tài khoản gốc. Đường nhận phải tính đến khi tìm tài khoản ngân hàng.
- Pay2S không ký body và không có timestamp: không chống được gửi lại request cũ, chỉ dựa vào khóa chống trùng theo `id`.

**Thứ tự làm:**
1. **SePay** trước: tài liệu rõ nhất, có HMAC, gói Free nhận tài khoản cá nhân.
2. **Pay2S** thứ hai: có sandbox để phát triển, và cách xác thực khác hẳn SePay, đủ để chứng minh lớp adapter.
3. **payOS** để giai đoạn 5, không bắt buộc. Mô hình của payOS là tạo link, nên adapter phải có cả chiều gọi đi.
4. **Casso** chỉ làm khi tìm được tài liệu chữ ký.

Nguồn:
- SePay:
  - https://developer.sepay.vn/en/sepay-webhooks/tich-hop-webhook
  - https://developer.sepay.vn/en/sepay-webhooks/xac-thuc
  - https://sepay.vn/bang-gia.html
- Pay2S:
  - https://developer.pay2s.vn/docs/webhook/tai-lieu-ky-thuat/
  - https://developer.pay2s.vn/docs/webhook/create-hook/
  - https://pay2s.vn/bang-gia
- payOS: https://payos.vn/docs/tich-hop-webhook/kiem-tra-du-lieu-voi-signature

---

## 5. Kiến trúc

### 5.1 Sơ đồ luồng nhận

```
Khách chuyển khoản ─► Tài khoản ngân hàng của shop
                         │ biến động số dư
                         ▼
        SePay / Pay2S / payOS ── webhook ──► POST /hooks/{provider}/{connection_uuid}
                                               1. Xác thực (HMAC / Bearer / chữ ký data)
                                               2. Lưu request gốc (inbound_events)
                                               3. Adapter → NormalizedTransaction[]
                                               4. INSERT bỏ trùng (provider, external_id)
                                               5. Khớp đơn bằng quy tắc, trong DB transaction
                                               6. Trả 200 {"success": true}
                                               ▼
                               Sự kiện: order.paid / order.partially_paid /
                                        order.overpaid / transaction.unmatched
                                  │                  │                  │
                                  ▼                  ▼                  ▼
                   Webhook ký HMAC tới     Tin Telegram cho     Gợi ý tự học (khi
                   hệ thống của shop       chủ shop             chưa khớp) → người duyệt
```

**Nguyên tắc:**
- Bước 1–6 chạy **ngay trong request**: chỉ là vài câu SQL, không gọi ra ngoài. Không dùng `afterResponse()`: spike cho thấy trên Wasmer nó giữ request lại (mục 10).
- Phần gọi ra ngoài (webhook, Telegram) đi qua **hàng đợi**.
- Nhờ vậy, việc "đơn chuyển sang đã thanh toán" không phụ thuộc vào worker hàng đợi, vốn là điểm yếu trên Wasmer.

### 5.2 Lớp adapter

```php
interface ProviderAdapter
{
    /** Ném InvalidSignature nếu sai. Luôn đọc $request->getContent() (body gốc), không json_decode trước. */
    public function verify(Request $request, ProviderConnection $connection): void;

    /** @return list<NormalizedTransaction> */
    public function parse(Request $request): array;

    /** Phản hồi nhà cung cấp mong đợi: SePay và Pay2S đều cần 200 + {"success": true}. */
    public function acknowledge(): Response;
}
```

`NormalizedTransaction` là DTO chỉ đọc, gồm các trường:
- `provider`, `externalId` (string), `accountNumber`, `bankCode`
- `direction` (enum `in` hoặc `out`; chuẩn hóa chữ hoa và chữ thường)
- `amount` (int VND, **không dùng float**), `content`, `referenceCode`, `providerCode` (`code` của SePay hoặc `paymentCode` của Pay2S)
- `transactedAt` (đổi từ giờ VN sang UTC), `payerName`, `payerAccount`, `raw` (array)

Các adapter: `SePayAdapter`, `Pay2SAdapter`, `SimulatorAdapter` (cho demo và test; ký HMAC bằng secret của demo), và `PayOSAdapter` ở giai đoạn 5.
Mỗi adapter có **fixture JSON** lấy từ ví dụ trong tài liệu chính thức. Mỗi fixture có test xác thực đúng, sai và hết hạn.

### 5.3 Mã đơn

- Định dạng là `tiền tố + 6 ký tự`, ví dụ `DH7K3QXM`.
  - Tiền tố do shop đặt, dài 2–4 chữ cái, mặc định `DH`.
  - 6 ký tự lấy từ bảng `23456789ABCDEFGHJKMNPQRSTVWXYZ`: đã bỏ `0 1 I L O U` vì dễ đọc nhầm. Bảng có 30 ký tự, nên có khoảng 729 triệu tổ hợp.
- Mã **không có gạch hay dấu**, vì nhiều ngân hàng xóa ký tự đặc biệt, đổi sang chữ hoa hoặc cắt bớt nội dung.
- DB có `UNIQUE` toàn hệ thống. Nếu sinh trùng thì sinh lại.

### 5.4 Khớp đơn

**Chuẩn hóa nội dung chuyển khoản:**
1. Bỏ dấu tiếng Việt, đổi sang chữ hoa.
2. Thay ký tự không phải chữ hoặc số bằng dấu cách.
3. Tìm mã theo **cả hai cách**: theo từ tách rời, và theo chuỗi liền. Ngân hàng hay chèn thêm tiền tố hoặc hậu tố dính liền vào nội dung, ví dụ `MBVCB.123456.DH7K3QXM.CT tu ...`.
4. Ưu tiên `providerCode` nếu nhà cung cấp đã tách sẵn.

**Quy tắc khớp tự động:**
1. Bỏ qua giao dịch có `direction = out`.
2. Mã phải là một đơn **của đúng shop** sở hữu kết nối đó.
3. Đơn phải đang `pending`, hoặc đã `expired` nhưng còn trong thời gian ân hạn 24 giờ. Trường hợp sau gắn cờ `paid_after_expiry`.
4. So số tiền giao dịch với số còn phải trả (`amount - paid_amount`):
   - Bằng nhau thì `paid`.
   - Ít hơn thì `partially_paid`.
   - Nhiều hơn thì `overpaid`. Gắn cờ để chủ shop xử lý tay.
5. **Không bao giờ** tự khớp chỉ dựa vào số tiền.

**Chống tranh chấp khi nhiều request đến cùng lúc:**
- Bọc toàn bộ trong `DB::transaction` và khóa đơn bằng `Order::lockForUpdate()`.
- Bảng `transactions` có `UNIQUE(shop_id, provider, external_id)`, nên retry của nhà cung cấp không ghi hai lần.
  - Khóa có `shop_id`: nếu chỉ là `(provider, external_id)` thì một shop có thể tự ký webhook giả (họ biết secret của chính mình) mang `external_id` của shop khác, làm giao dịch thật của shop kia bị coi là trùng.

**Trùng chéo giữa các nhà cung cấp:** một tài khoản ngân hàng có thể nối cùng lúc qua SePay và Pay2S, khi đó một giao dịch đến hai lần. Thêm khóa phụ `fingerprint` = sha256(`bank_account_id | amount | nội dung đã chuẩn hóa | thời điểm làm tròn phút`). Nếu trùng thì đánh dấu `duplicate_of`, không khớp lần hai.
- Không dùng mã tham chiếu, vì chưa chắc `referenceCode` của SePay và `transactionNumber` của Pay2S là cùng một mã.
- Chỉ coi là trùng khi bản kia đến từ **nhà cung cấp khác**: hai lần chuyển giống hệt nhau qua cùng một nhà cung cấp là hai giao dịch thật.
- Giới hạn: nếu hai nhà cung cấp ghi giờ lệch nhau qua mốc phút thì không bắt được. **[cần kiểm tra]** khi nối thật cả hai.
- Hai bản đến cùng lúc: `SELECT … FOR UPDATE` theo fingerprint giữ gap lock, một bên gặp deadlock và `DB::transaction(…, 3)` chạy lại, lần sau thấy bản gốc.

**Quy tắc so số tiền đã chốt (18/10/2026):** đơn đã `paid` hoặc `overpaid` mà nhận thêm tiền thì giao dịch để **chưa khớp** cho người duyệt, không tự chuyển sang `overpaid`. Ân hạn tính theo giờ ngân hàng ghi nhận (`transactedAt`), không theo lúc webhook đến.

**Không khớp được:** giao dịch chuyển sang `unmatched`, rồi sinh gợi ý (mục 8).

### 5.5 Webhook gửi tới shop

Ví dụ payload:

```json
{
  "id": "evt_01JB8Z…",
  "type": "order.paid",
  "created_at": "2026-10-18T02:00:00Z",
  "data": {
    "order": { "code": "DH7K3QXM", "external_ref": "WOO-1234", "amount": 150000,
               "paid_amount": 150000, "status": "paid", "paid_at": "2026-10-18T02:00:00Z" },
    "transaction": { "amount": 150000, "content": "DH7K3QXM", "bank": "ACB", "reference": "FT2629…" }
  }
}
```

**Chữ ký**, theo kiểu Stripe:
- Header `X-Gateway-Signature: t={unix},v1={hex(hmac_sha256(secret, "{t}.{raw_body}"))}`.
- Thêm header `X-Gateway-Event-Id` để phía shop chống xử lý trùng.

**Retry:**
- Gửi lại sau 1 phút, 5 phút, 15 phút, 1 giờ, 3 giờ, 6 giờ, 12 giờ: tổng 7 lần trong khoảng 22 giờ.
- Lần gửi thành công là khi nhận 2xx trong vòng 10 giây.
- Sau 7 lần thì trạng thái là `dead`.
- Endpoint thất bại liên tiếp quá ngưỡng thì tự tắt và báo Telegram cho chủ shop.

**Nhật ký** từng lần gửi lưu mã HTTP, 2 KB đầu của body phản hồi và thời gian. Có nút **Gửi lại**, và lệnh `POST /api/v1/webhook-endpoints/{id}/test`.

**Chặn SSRF:**
- URL đích phải là `https`.
- Phân giải DNS rồi chặn IP nội bộ, loopback, link-local và metadata (`169.254.169.254`).
- Kiểm tra lại cả khi bị chuyển hướng.

Kèm một **script mẫu** (PHP và Node) để phía shop kiểm tra chữ ký. Đưa script này vào README.

### 5.6 API cho lập trình viên (v1)

- Token Sanctum gắn với **Shop**: dùng `HasApiTokens` trên model `Shop`, không gắn với User. Quyền theo token: `orders:read`, `orders:write`, `transactions:read`.
- Hỗ trợ header `Idempotency-Key` cho `POST`: cùng khóa và cùng body thì trả lại kết quả cũ.

| Method | Đường dẫn | Ghi chú |
|---|---|---|
| POST | `/api/v1/orders` | Body: `amount`, `external_ref?`, `description?`, `customer_name?`, `expires_in?`. Trả về `code`, `qr_string`, `qr_svg_url`, `pay_url`, `transfer{bank, account_number, account_name, memo}` |
| GET | `/api/v1/orders/{code}` | |
| GET | `/api/v1/orders?status=` | Phân trang theo cursor |
| POST | `/api/v1/orders/{code}/cancel` | |
| GET | `/api/v1/transactions` | |
| POST | `/api/v1/webhook-endpoints/{id}/test` | |

Tài liệu API sinh tự động bằng `dedoc/scramble`. Nó có hỗ trợ Laravel 13 không: **[cần kiểm tra]**.

### 5.7 Trang công khai và VietQR

- `/pay/{code}`: QR, ngân hàng, số tài khoản, nút sao chép, đồng hồ đếm ngược.
  - Trang **không tạo session hay cookie** (xem bẫy Wasmer ở mục 14).
  - Trang hỏi `/pay/{code}/status` 5 giây một lần, dừng khi đã thanh toán hoặc hết hạn, và tối đa 15 phút.
- `/qr/{code}.svg`: ảnh QR vẽ bằng `chillerlan/php-qrcode`. Thư viện này viết bằng PHP thuần và xuất SVG, không cần GD.
- Chuỗi VietQR (chuẩn EMVCo/NAPAS) tự dựng trong app, không gọi API ngoài:
  - `00`=`01`; `01`=`12` (QR động, có số tiền)
  - `38` = { `00`=`A000000727`, `01`={ `00`=BIN ngân hàng, `01`=số tài khoản }, `02`=`QRIBFTTA` }
  - `53`=`704`, `54`=số tiền, `58`=`VN`, `62`={ `08`=nội dung }
  - `63`=CRC16-CCITT (đa thức 0x1021, giá trị đầu 0xFFFF), tính trên toàn chuỗi kể cả `6304`
- Danh sách ngân hàng và BIN lấy từ `https://api.vietqr.io/v2/banks` (76 ngân hàng; trường `bin`, `code`, `shortName`, `transferSupported`). Chụp vào `database/data/banks.json`, đọc qua `App\Payments\Banks\BankDirectory`; không gọi API lúc chạy.
- **[cần kiểm tra]** Quét QR bằng ít nhất hai app ngân hàng khác nhau để chắc chuỗi đúng.

### 5.8 Nhiều shop và phân quyền

- Bảng `shop_user` có cột `role` (`owner` hoặc `staff`).
  - Owner quản lý kết nối, webhook, token API và thành viên.
  - Staff xem đơn, tạo đơn, duyệt gợi ý.
- Mọi model của một shop có global scope theo `shop_id` hiện tại (`ShopScope`). Trên dashboard, Filament gắn thêm scope tenant của riêng nó, nên có hai lớp lọc độc lập.
- Trang chỉ dành cho owner (tài khoản ngân hàng, kết nối, thành viên) chặn bằng `canAccess()` qua `App\Tenancy\ShopAccess`. Policy cho API thêm ở GĐ2.
- **Mời thành viên bằng link**, vì app không gửi email:
  - Owner nhập email và vai trò, app tạo link `/app/invitations/{token}` để owner tự gửi qua Zalo, Messenger. Token 48 ký tự, bảng chỉ lưu sha256, link hết hạn sau 7 ngày, dùng một lần. Mời lại cùng email thì làm mới lời mời cũ và link cũ hết dùng.
  - Người được mời mở link: chưa có tài khoản thì đặt tên và mật khẩu ngay tại đó; đã có tài khoản thì đăng nhập rồi quay lại; đang đăng nhập bằng email khác thì phải đăng xuất. Chỉ nhận được dưới đúng email ghi trong lời mời.
  - Shop luôn còn ít nhất một owner: đổi vai trò và xóa thành viên khóa các dòng owner bằng `lockForUpdate` trước khi đếm. Owner không tự đổi vai trò hay tự xóa mình trên giao diện.
  - Mọi thao tác ghi `audit_logs`: `member.invited`, `member.invitation_renewed`, `member.invitation_revoked`, `member.joined`, `member.role_changed`, `member.removed`.
- Bộ test **cách ly tenant**: user của shop A gọi mọi route của shop B phải nhận 404 hoặc 403 (`tests/Feature/Dashboard`).
- Dashboard dùng **Filament 5.10.2 với Livewire 4.4.7**, chạy được trên Laravel 13 (đã dựng và test ngày 18/10/2026). Panel ở `/app/{shop-slug}`, tenancy qua `->tenant(Shop::class, slugAttribute: 'slug')`.

### 5.9 Thông báo Telegram

- Wyatt tự tạo bot qua @BotFather (mục 13).
- Chủ shop nối Telegram qua link `t.me/<bot>?start=<mã dùng một lần>`. Webhook của bot nhận lệnh `/start <mã>` rồi lưu `chat_id`.
- Mẫu tin: `+150.000đ · DH7K3QXM · đã khớp`, hoặc `+200.000đ · chưa khớp · "chuyen tien ao"`.

### 5.10 Chế độ demo

- `/demo` có hai shop mẫu dựng sẵn. Người xem bấm "Vào dashboard demo" để đăng nhập tự động bằng tài khoản staff của shop mẫu.
- Nút **"Giả lập khách chuyển khoản"**:
  1. Tạo payload theo kiểu SePay và ký bằng secret của demo.
  2. Gửi vào **đúng đường nhận thật** qua `SimulatorAdapter`, không đi đường tắt.
  3. Có tùy chọn gõ sai nội dung để thấy gợi ý tự học.
- Một lệnh hẹn giờ xóa và dựng lại dữ liệu demo mỗi đêm. Dữ liệu demo kèm sẵn mẫu phản hồi, để trang mô hình có số liệu ngay (mục 8).

---

## 6. Mô hình dữ liệu

| Bảng | Cột chính |
|---|---|
| `users` | Mặc định của Laravel |
| `shops` | `name`, `slug`, `order_prefix`, `timezone`, `telegram_chat_id`, `settings` (json), `is_demo` |
| `shop_user` | `shop_id`, `user_id`, `role` |
| `shop_invitations` | `shop_id`, `email`, `role`, `token_hash` (sha256 của token trong link, unique), `invited_by`, `expires_at` (7 ngày), `accepted_at`, `accepted_by` |
| `bank_accounts` | `shop_id`, `bank_bin`, `bank_code`, `account_number`, `account_name`, `label`, `is_default` |
| `provider_connections` | `shop_id`, `uuid` (UUIDv4, nằm trong URL webhook), `provider` (sepay, pay2s, payos, simulator), `bank_account_id` (null: lấy theo số tài khoản trong payload), `label`, `credentials` (cast `encrypted:array`), `status`, `last_received_at` |
| `orders` | `shop_id`, `bank_account_id` (tài khoản nhận tiền của đơn), `code` (unique), `external_ref` (unique theo shop), `amount`, `paid_amount`, `status`, `description`, `customer_name`, `expires_at`, `paid_at`, `paid_after_expiry`, `created_via`, `metadata` (json) |
| `inbound_events` | `shop_id`, `provider_connection_id`, `headers` (json, đã che token), `body` (text), `signature_valid`, `ip`, `error`, `received_at`. Giữ 30 ngày |
| `transactions` | `shop_id`, `provider_connection_id`, `inbound_event_id`, `provider`, `external_id`, **UNIQUE(shop_id, provider, external_id)**, `bank_account_id`, `direction`, `amount`, `content`, `reference_code`, `provider_code`, `transacted_at`, `payer_name`, `payer_account`, `raw` (json), `status` (matched, unmatched, suggested, ignored, duplicate), `order_id`, `matched_by` (rule, manual, suggestion_approved), `matched_by_user_id`, `matched_at`, `fingerprint` (chống trùng chéo nhà cung cấp), `duplicate_of` |
| `match_suggestions` | `transaction_id`, `order_id`, `model_version`, `score`, `features` (json), `reason`, `status`, `decided_by`, `decided_at` |
| `match_feedback` | `shop_id`, `transaction_id`, `order_id`, `features` (json), `label` (0 hoặc 1), `source` (approved, rejected, manual), `is_demo` |
| `match_models` | `version`, `weights` (json), `bias`, `samples`, `precision`, `coverage`, `trained_at`, `is_active`, `scope` (global hoặc demo) |
| `webhook_endpoints` | `shop_id`, `url`, `secret` (encrypted), `events` (json), `active`, `consecutive_failures`, `disabled_at` |
| `webhook_deliveries` | `webhook_endpoint_id`, `event_id`, `event_type`, `payload`, `attempt`, `status`, `next_attempt_at`, `response_code`, `response_body` (2 KB), `duration_ms` |
| `idempotency_keys` | `shop_id`, `key`, `request_hash`, `response` (json), `expires_at` |
| `audit_logs` | `shop_id`, `user_id`, `action`, `subject_type`, `subject_id`, `meta`, `ip` |
| `personal_access_tokens` | Của Sanctum, `tokenable` là Shop |

Hàng đợi, cache và session đều dùng driver `database`, vì Wasmer không có Redis.

---

## 7. Bảo mật (checklist)

- [ ] Xác thực chữ ký trên **body gốc** (`$request->getContent()`) và so bằng `hash_equals`. Kiểm tra cửa sổ thời gian 5 phút với SePay.
- [ ] URL webhook nhận chứa UUID ngẫu nhiên của từng kết nối, không dùng id tăng dần.
- [ ] Bí mật của nhà cung cấp và webhook được mã hóa khi lưu (`encrypted` cast), và chỉ hiện một lần.
- [ ] Giới hạn tần suất cho từng kết nối, giới hạn kích thước body (ví dụ 64 KB), loại các route `/hooks/*` khỏi CSRF.
- [ ] Số tiền luôn là số nguyên VND. Khớp đơn dùng `lockForUpdate`.
- [ ] Chặn SSRF cho webhook gửi đi (mục 5.5).
- [ ] Log và `inbound_events` phải che token và header `Authorization`.
- [ ] Test cách ly tenant cho mọi route.
- [ ] Nội dung chuyển khoản do khách gõ nên **không đáng tin**. Chỉ dùng nó để tính đặc trưng, và luôn escape khi hiển thị. Gợi ý chỉ được chọn trong danh sách ứng viên của đúng shop (mục 8).
- [ ] Mô hình dùng chung học từ phản hồi của mọi shop, nên phải chống làm lệch: giới hạn số mẫu mỗi shop, không học từ shop demo, và mô hình mới phải đạt ngưỡng trên bộ fixture cố định (mục 8).
- [ ] Chạy gitleaks trước khi commit (có sẵn ở `D:\SETUP\gitleaks\gitleaks.exe`). File `.env.example` không chứa giá trị thật.

---

## 8. Gợi ý khớp đơn tự học (không gọi API ngoài)

Wyatt quyết định ngày 18/10/2026: **không nối dịch vụ AI bên ngoài**. Không có khóa API, không tốn tiền, và dữ liệu giao dịch
không rời khỏi server. Thay vào đó là một mô hình nhỏ (**hồi quy logistic**) viết bằng PHP thuần. Nó chạy ngay trong app và
học dần từ quyết định của nhân viên.

**Khi nào chạy:** ngay sau khi một giao dịch rơi vào `unmatched`. Việc chấm điểm chỉ gồm vài câu SQL và phép tính nhỏ, nên chạy
luôn trong request được. Nếu đo thấy chậm hơn khoảng 200 ms thì chuyển sang hàng đợi.

**Bước 1, lấy ứng viên.** Đơn phải thỏa cả ba điều kiện:
- của cùng shop, và đang `pending`, `partially_paid`, hoặc `expired` chưa quá 24 giờ;
- tạo trong 72 giờ gần nhất;
- nằm trong 50 đơn mới nhất.

**Bước 2, tính đặc trưng.** Mỗi đặc trưng tính cho một cặp (giao dịch, đơn) và nằm trong khoảng 0–1:

| Đặc trưng | Cách tính | Bắt được trường hợp |
|---|---|---|
| `code_sim` | Trượt một cửa sổ dài bằng mã (và ±1 ký tự) trên nội dung đã chuẩn hóa. Lấy khoảng cách Damerau-Levenshtein nhỏ nhất `d`, rồi tính `1 - d / độ dài mã` | Sai 1 ký tự, đảo 2 ký tự, thiếu 1 ký tự |
| `suffix_hit` | Nội dung có chứa 6 ký tự sau tiền tố hay không | Khách quên tiền tố, chỉ ghi `7K3QX…` |
| `amount_exact` | 1 nếu số tiền bằng số còn phải trả | |
| `amount_close` | `max(0, 1 - chênh lệch / số còn phải trả)` | Khách trừ phí hoặc làm tròn |
| `time_decay` | `exp(-số giờ từ lúc tạo đơn / 12)` | Khách chuyển ngay sau khi đặt |
| `name_sim` | Độ trùng từ (Jaccard) giữa tên khách và tên người chuyển, sau khi bỏ dấu. Tên người chuyển lấy từ `extraData.remitterName` của Pay2S hoặc từ chữ trong nội dung | "NGUYEN VAN AN" và "Nguyễn Văn An" |
| `ambiguity` | `1 / số đơn mở có cùng số tiền` | Nhiều đơn cùng giá thì giảm độ tự tin |
| `expired` | 1 nếu đơn đã hết hạn | |

**Bước 3, chấm điểm.** Điểm là xác suất `p = sigmoid(w · x + b)`, nằm trong khoảng 0–1.
- Lúc đầu dùng **trọng số đặt tay**, với `code_sim` và `amount_exact` nặng nhất.
- Chỉ hiện gợi ý khi `p ≥ 0,6` **và** cao hơn ứng viên thứ hai ít nhất 0,2. Nhờ vậy không gợi ý bừa khi hai đơn ngang nhau.

**Bước 4, lý do dễ hiểu.** Xếp các đóng góp `wᵢ · xᵢ` từ lớn đến nhỏ, rồi viết 2–3 cái đầu thành câu.
Ví dụ: "Mã gần giống (sai 1 ký tự) · Đúng số tiền · Chuyển 3 phút sau khi tạo đơn".

**Bước 5, tự học từ nhân viên.**
- Mỗi quyết định lưu một mẫu (vector đặc trưng kèm nhãn) vào bảng `match_feedback`:
  - Duyệt gợi ý: nhãn 1.
  - Bỏ qua gợi ý: nhãn 0.
  - Tự chọn đơn khác bằng tay: đơn được chọn nhãn 1, gợi ý sai nhãn 0.
- Lệnh `php artisan match:train` chạy mỗi đêm:
  - Huấn luyện hồi quy logistic bằng gradient descent có phạt L2. Khoảng 50 dòng PHP, không dùng thư viện.
  - Chia dữ liệu 80/20 để kiểm tra. Mô hình mới chỉ được dùng khi nó **tốt hơn mô hình đang chạy** trên phần kiểm tra
    **và** đạt ngưỡng trên bộ fixture cố định.
  - Cần ít nhất khoảng 50 mẫu mới bắt đầu học. Trước đó vẫn dùng trọng số đặt tay.
- Mỗi phiên bản mô hình lưu trong `match_models`: trọng số, số mẫu, độ chính xác, ngày huấn luyện.
- Bắt đầu với một mô hình dùng chung cho mọi shop, vì mỗi shop riêng có ít dữ liệu.
  - Giới hạn số mẫu của mỗi shop trong một lần huấn luyện, để một shop không làm lệch mô hình của cả hệ thống.
  - **Phản hồi từ shop demo không bao giờ vào dữ liệu huấn luyện thật**, vì khách xem demo có thể bấm bừa. Demo dùng mô hình riêng.

**Không bao giờ tự xác nhận.** Gợi ý chỉ hiện nút Duyệt. Khi duyệt, app ghi `matched_by = suggestion_approved` và một dòng `audit_logs`.

**Trang "Mô hình khớp đơn" trên dashboard** là điểm nhấn cho nhà tuyển dụng. Trang hiện trọng số đang dùng dưới dạng biểu đồ
thanh, số mẫu, và độ chính xác của từng phiên bản.

**Bộ đánh giá:**
- Khoảng 50 ca (nội dung, danh sách đơn, đáp án đúng) đặt trong `tests/Fixtures/matching/`.
- `php artisan match:eval` in ra hai số và so trọng số đặt tay với trọng số đã học:
  - **Độ chính xác:** trong các gợi ý đưa ra, bao nhiêu phần trăm đúng.
  - **Độ phủ:** bao nhiêu phần trăm ca có gợi ý.
- CI chạy `match:eval` và báo lỗi khi độ chính xác tụt dưới ngưỡng đã ghi.
- Dữ liệu demo kèm sẵn vài trăm mẫu phản hồi giả lập, để trang mô hình có số liệu ngay.

**Muốn thêm LLM về sau:** cắm thêm một bộ gợi ý khác sau cùng interface `MatchSuggester`. Dự án này không cần.

---

## 9. Stack và thư viện

| Phần | Chọn | Ghi chú |
|---|---|---|
| Ngôn ngữ, framework | PHP 8.3, Laravel 13 (lúc tra 18/10/2026: `laravel/laravel` 13.11, framework 13.37) | PHP của Larabox: `C:/Users/Wyatt/Larabox/bin/php/8.3.33/php.exe` |
| DB máy local và CI | Larabox chạy **MariaDB 12.2** (không phải MySQL). CI dùng service `mysql:8.4` | Production là MySQL 8.0 của Wasmer. Migration chỉ dùng tính năng có ở cả MariaDB và MySQL |
| Dashboard | Filament 5 (tenancy) | ✅ Filament 5.10 hỗ trợ `illuminate ^13`, chạy trên Livewire 4 |
| API | Sanctum, `dedoc/scramble` | ✅ Scramble 0.13.48 hỗ trợ Laravel 13 |
| QR | `chillerlan/php-qrcode` 5 (đang dùng 5.0.5) | Xuất SVG, không cần GD |
| Gợi ý khớp đơn | PHP thuần (hồi quy logistic tự viết) | Không thư viện, không API ngoài |
| Test và chất lượng | Pest 4 (ghim `^4.7`), Pint, Larastan 3 (level ≥ 6) | Pest 5 đòi PHP 8.4, còn Wasmer chạy 8.3 |
| Giao diện | CSS viết tay, taste-skill (mục 9a) | `/pay` dùng `public/css/pay.css` và `pay.js`, không build. Dashboard giữ theme Filament, ghi đè ở `public/css/panel.css`. Deploy không cần Node |
| Tunnel test webhook ở máy | `cloudflared`: tunnel có tên `btg-dev` (mục 10a). Quick tunnel làm dự phòng | Đặt portable ở `D:\SETUP\cloudflared`. Quick tunnel: `cloudflared tunnel --url http://localhost:8000` |

Ghi mọi thư viện thêm vào README, mục "Cài đặt".

---

## 9a. Giao diện: không ra kiểu template AI

Wyatt dặn ngày 18/10/2026: giao diện không được "slop công nghiệp", tức trông như trang AI ghép từ mẫu.

**Bộ skill:** taste-skill (Leonxlnx, MIT, https://tasteskill.dev).
- Đã clone ở `D:\Repo\taste-skill` (commit `f624f37`, ngày 14/10/2026). Cập nhật bằng `git pull`.
- Liên kết junction vào `.claude/skills/` của dự án: `design-taste-frontend` và `minimalist-ui`. Thư mục này nằm trong `.gitignore`, nên repo không chứa bản sao skill.
- Script của skill cần Node 22+ (máy có Node 24) và một trình duyệt Chromium. Đặt `CHROME_PATH` là Brave của Wyatt.
- Đặt `TASTE_SKILL_HISTORY` về thư mục `.taste-skill/` của dự án (đã gitignore), không ghi ra ổ C ngoài dự án.
- Đã đọc các script trước khi dùng. Chúng chỉ mở trình duyệt headless với hồ sơ tạm, tìm ảnh ở các bộ sưu tập mở, và ghi file trong dự án.

**Bề mặt nào dùng gì:**

| Bề mặt | Skill | Cách dùng |
|---|---|---|
| Trang giới thiệu `/` và `/demo` | `design-taste-frontend` | Chạy đủ quy trình của skill. Liệt kê chất liệu riêng của dự án, bốc 3 hướng thiết kế rồi chọn 1, viết câu ý tưởng, viết chữ trước khi dựng. Sau đó chạy `lint.mjs` và `inspect.mjs` ở 3 độ rộng 1440, 768, 390. Tự soi ảnh chụp và sửa, tối đa 3 vòng. Cuối cùng thêm chuyển động |
| Trang thanh toán `/pay/{code}` | `design-taste-frontend`, theo trường hợp "một thành phần" | Không bốc hướng mới, dùng màu và phông của trang giới thiệu. Giữ luật của skill và chạy `inspect.mjs`. Ưu tiên đọc rõ trên điện thoại: số tiền, QR, số tài khoản, nội dung chuyển khoản phải ở cỡ lớn |
| Dashboard Filament | `minimalist-ui` | Skill chính không dành cho dashboard. Đổi theme Filament: phông riêng (Filament mặc định dùng Inter, skill cấm Inter), bảng màu trầm, bỏ bóng đổ |

Hướng thiết kế (màu, phông) chọn ngay ở GĐ1 khi dựng `/pay`. Trang giới thiệu đầy đủ làm ở GĐ5.

**Phông chữ:**
- Chỉ chọn phông có bộ ký tự `vietnamese` (xem trên Google Fonts). Nhiều phông của Fontshare không có dấu tiếng Việt. Nếu bốc phải phông không có tiếng Việt thì `--redraw type`.
- Tự host phông trong `public/fonts`. Nhờ vậy trang `/pay` không gọi sang Google và không có cookie bên thứ ba.

**Tham khảo trên mạng** (Wyatt cho phép 18/10/2026):
- Claude được xem các trang web để lấy ý về màu, phông, cách đặt heading.
- Chỉ lấy ý. Không chép logo hay nhận diện thương hiệu, không làm trang giống ngân hàng, SePay hay Pay2S.
- Ghi các trang đã tham khảo và quyết định rút ra vào `docs/DESIGN.md`.

**Hình ảnh** (Wyatt cho phép Claude tự tạo, 18/10/2026):
- Vẽ bằng code (SVG): minh họa, sơ đồ kiến trúc, biểu tượng, đúng bảng màu đã chọn.
- Ảnh chụp giao diện thật bằng `inspect.mjs` hoặc trình duyệt, dùng cho README và ảnh thẻ profile ngang 1,6:1.
- Ảnh từ bộ sưu tập mở bằng `assets.mjs` (Openverse, Wikimedia Commons, các bảo tàng). Chỉ lấy CC0, public domain, CC BY, CC BY-SA. Ghi credit trên trang và trong `credits.json`.
- Không dùng ảnh tiền giấy Việt Nam. Không tạo ảnh hay lời giả làm khách hàng, lời khen, số liệu kinh doanh.

---

## 10. Hosting và triển khai (miễn phí)

| Thành phần | Nơi đặt | Giới hạn gói miễn phí |
|---|---|---|
| App Laravel | Wasmer Edge, gói Hobby, vùng `us-losa1` | Trang giá ghi 1 triệu request/tháng, nhưng banner trên cùng trang và trang Laravel ghi 100 nghìn: **[cần kiểm tra trong dashboard]**. PHP 8.3.4 (WebAssembly). **Không có vùng châu Á** |
| Database | **MySQL của Wasmer** (`capabilities.database`), cùng vùng `us-losa1` với app. Wyatt chốt 18/10/2026 | 100 MB, không backup. Dữ liệu demo dựng lại mỗi đêm, `inbound_events` chỉ giữ 30 ngày |
| Nhịp mỗi phút | Cloudflare Worker cron (tài khoản rirtar.lofi) gọi `POST /internal/tick` | Gói Free của Workers (mục 10a) |
| Tên miền | Subdomain của `wyattos.cyou` (ví dụ `pay.wyattos.cyou`), DNS ở Cloudflare, tài khoản rirtar.lofi@gmail.com | Xem mục 10a |
| Lối nhận dự phòng (không bắt buộc) | Cloudflare Worker và Queues (gói Free: lưu 24 giờ, 100 nghìn request/ngày) | Đứng trước app để đỡ lúc app khởi động nguội |

**Hạn mức request:** webhook nhận vào và việc hỏi trạng thái trên trang `/pay` đều tính vào 100 nghìn request mỗi tháng. Mỗi phiên thanh toán hỏi tối đa 180 lần (5 giây một lần trong 15 phút), nên đủ cho khoảng 500 phiên mỗi tháng. Quá đủ cho demo.

**Spike giai đoạn 0, làm trước mọi thứ.** Deploy một app Laravel tối giản lên Wasmer, có các route thử:

1. Kết nối TiDB qua TLS, rồi đọc và ghi một dòng.
2. **Gửi HTTPS ra ngoài** (Guzzle hoặc curl), ví dụ tới `https://api.telegram.org`. Webhook gửi đi và Telegram đều cần bước này.
3. **Chạy lệnh theo lịch mỗi phút** (job cron của Wasmer gọi `schedule:run`). Cú pháp YAML cho PHP: **[cần kiểm tra]**.
4. `dispatch(...)->afterResponse()` có chạy không. **[cần kiểm tra]**

**Nếu spike hỏng:**
- (2) hoặc (3) hỏng thì chuyển phần gửi đi sang **Cloudflare Worker có cron**:
  1. App ghi việc cần gửi vào DB.
  2. Worker gọi `/internal/tick` (ký HMAC) mỗi phút để lấy việc đến hạn.
  3. Worker gửi, rồi báo kết quả về app.
- Cron có thể dùng dịch vụ ngoài miễn phí, như cron-job.org (1 phút một lần), gọi `/internal/tick`.
- (1) hỏng thì thử MySQL đi kèm Wasmer (100 MB).

### Kết quả spike (18/10/2026)

App thử `btg-spike` (vùng `us-losa1`), mã ở nhánh `spike/wasmer`.

| Câu | Kết quả | Chi tiết |
|---|---|---|
| 1. Database | ✅ với MySQL của Wasmer | MySQL 8.0.45, TLS `TLS_AES_256_GCM_SHA384`, đọc và ghi được. Cần `DB_SSL_VERIFY=false` (CA riêng) và `MYSQL_ATTR_SSL_CA` để bật TLS. Mỗi request khoảng 0,68 giây khi gọi từ Việt Nam. TiDB chưa thử, vì cần Wyatt tạo tài khoản |
| 2. HTTPS ra ngoài | ✅ | Gọi `api.telegram.org` mất 512 ms. Có đủ extension: pdo_mysql, openssl, curl, mbstring, intl, bcmath, sodium, gd, zip |
| 3. Lịch mỗi phút | ❌ | Job `fetch` không chạy lần nào, cả với `'* * * * *'` lẫn `1m`. Job `execute` chỉ chạy một lần ngay sau deploy. Bản cấu hình Wasmer biên dịch (`json_config`) không có phần `jobs`. Ngoài ra `schedule:run` luôn hỏng, vì nó tìm đường dẫn `php` bằng `exec()` |
| 4. `afterResponse()` | ❌ | Không có `fastcgi_finish_request` (SAPI là `cli-server`). Request bị giữ tới khi việc chạy sau xong (2,7 giây với `sleep 2`), và lỗi của việc đó còn bị nối vào cuối nội dung phản hồi |

**Hệ quả cho thiết kế:**
- **Không dùng `afterResponse`, `Schedule::command`, `schedule:run`, `Process` hay bất kỳ thứ gì fork tiến trình.** Mọi việc chạy trong tiến trình hiện tại.
- **Nhịp mỗi phút đến từ bên ngoài:** một cron gọi `POST /internal/tick`, ký HMAC trong header. Route này làm hai việc:
  1. Chạy các việc đến hạn của `Schedule` bằng `dueEvents()`, chỉ dùng `Schedule::call` hoặc `Schedule::job`.
  2. Xử lý hàng đợi bằng `queue:work --stop-when-empty --max-time=20`, gọi trong tiến trình.
- **Đề xuất nguồn cron:** Cloudflare Worker cron trên tài khoản rirtar.lofi (mục 10a, phần 4). Mã để trong `edge/` của repo. Dự phòng: cron-job.org.
- **Migration khi deploy:** job `post-deployment` có chạy, nhưng không thấy secret `DB_*` của app nên migrate vào một database khác. Thay bằng bước CI gọi `POST /internal/migrate` có ký HMAC, ngay sau khi deploy.
- **Bí mật không bao giờ nằm trong URL:** log truy cập của Wasmer ghi nguyên URL, kể cả query string.
- `scaling.mode: single_concurrency`: mỗi instance xử lý một request một lúc, nên `/internal/tick` phải ngắn (dưới 20 giây).

**Quyết định của Wyatt (18/10/2026):** dùng **MySQL của Wasmer**, và cron qua **Cloudflare Worker** trên tài khoản rirtar.lofi. Không dùng TiDB. App thử `btg-spike` đã xóa cùng ngày.

Các phương án đã cân nhắc:

| Phương án | Ưu | Nhược |
|---|---|---|
| **MySQL của Wasmer** (đã chọn) | Cùng vùng với app, đã chạy thật trong spike, không cần thêm tài khoản, có `SKIP LOCKED` | 100 MB, không backup, CA riêng (không kiểm được chứng chỉ) |
| TiDB Starter ở Oregon, ghim app ở `us-hillsboro` | 5 GiB, TLS chuẩn | Thêm tài khoản; không có `SKIP LOCKED` (đặt `DB_SERVER_VERSION=5.7.25`); chưa thử |
| TiDB Starter ở Singapore (kế hoạch cũ) | Gần khách Việt Nam | App ở Mỹ nên mỗi câu SQL đi xuyên Thái Bình Dương |

**Hàng đợi khi không có worker chạy liên tục:** `QUEUE_CONNECTION=database`. Mỗi phút, `/internal/tick` chạy `queue:work --stop-when-empty --max-time=20` trong tiến trình (xem "Kết quả spike").

**CI và triển khai:** GitHub Actions gồm hai job.
- `tests`: Pint `--test`, Larastan, Pest với service MySQL.
- `deploy`: chỉ chạy trên `main` sau khi test xanh. Cần biến `WASMER_OWNER` và bí mật `WASMER_TOKEN`, do Wyatt tự thêm.
- Script `scripts/build-wasmer.sh` đóng gói thư mục `build/wasmer` sạch: không có `.env` của máy, chỉ thư viện production, `.env` production không chứa bí mật. Bí mật đặt trong Wasmer.

---

## 10a. Cloudflare (tài khoản rirtar.lofi@gmail.com)

Wyatt chốt ngày 18/10/2026: mọi thứ trên Cloudflare của dự án chỉ dùng **tài khoản rirtar.lofi@gmail.com**.

**Kiểm tra tài khoản trước mỗi lần làm:**
- Cài `wrangler` làm dev dependency của dự án (`npm i -D wrangler`), không cài toàn cục.
- Chạy `npx wrangler whoami`, phải thấy rirtar.lofi@gmail.com. Máy đã có một phiên đăng nhập wrangler cũ, chưa biết của tài khoản nào: **[cần kiểm tra]**.
- Sai tài khoản thì dừng lại. Wyatt tự chạy `npx wrangler logout`, rồi `npx wrangler login` bằng rirtar.lofi.
- Không dùng connector Cloudflare của claude.ai cho dự án này, trừ khi Wyatt xác nhận connector đó là tài khoản rirtar.lofi.
- Không đọc các file chứa token: `%APPDATA%\xdg.config\.wrangler\config\default.toml`, `cert.pem` và các file `*.json` của cloudflared.
- Ở lần làm đầu, kiểm tra zone `wyattos.cyou` có nằm trong tài khoản rirtar.lofi không: **[cần kiểm tra]**.

**1. Tunnel cho máy phát triển (GĐ1)**
- Cách nhanh: quick tunnel `cloudflared tunnel --url http://localhost:8000`, không cần tài khoản. Nhược điểm: địa chỉ `*.trycloudflare.com` đổi mỗi lần chạy, nên phải dán lại URL webhook ở SePay và Pay2S.
- Cách đề xuất: **tunnel có tên** `btg-dev` trên tài khoản rirtar.lofi, với địa chỉ cố định `dev-pay.wyattos.cyou`.
  1. Wyatt chạy `cloudflared tunnel login` một lần và chọn zone `wyattos.cyou`.
  2. Claude chạy `cloudflared tunnel create btg-dev` rồi `cloudflared tunnel route dns btg-dev dev-pay.wyattos.cyou`.
  3. File cấu hình chỉ mở các đường cần nhận từ bên ngoài, còn lại trả 404:

     ```yaml
     tunnel: btg-dev
     credentials-file: D:\SETUP\cloudflared\btg-dev.json
     ingress:
       - hostname: dev-pay.wyattos.cyou
         path: ^/(hooks|telegram)/
         service: http://localhost:8000
       - service: http_status:404
     ```
- `cloudflared` bản portable đặt ở `D:\SETUP\cloudflared`. File credentials cũng để ở đó. Không commit file này, không đọc nội dung của nó.

**2. DNS cho production (GĐ3)**
- `pay.wyattos.cyou` trỏ về Wasmer theo hướng dẫn tên miền riêng của Wasmer. **[cần kiểm tra]:** loại bản ghi, và lúc cấp chứng chỉ phải để DNS only hay để proxied được.
- Đặt SSL/TLS ở chế độ **Full (strict)**.
- Claude soạn sẵn bản ghi chính xác. Wyatt tự thêm trên dashboard, hoặc cho phép Claude thêm qua Brave (đã đăng nhập rirtar.lofi). Claude hỏi lại đúng lúc làm.

**3. Quy tắc cache và bảo vệ (gói Free)**

| Đường dẫn | Quy tắc | Lý do |
|---|---|---|
| `/qr/*.svg` | Cache 1 ngày ở edge | Ảnh QR của một mã đơn không đổi. Đỡ request cho Wasmer |
| `/pay/*`, `/hooks/*`, `/api/*`, `/telegram/*` | Không cache | Nội dung động, có trạng thái |
| `/hooks/*`, `/telegram/*` | Bỏ Browser Integrity Check (configuration rule) | Request đến từ server của SePay, Pay2S, Telegram, không phải trình duyệt |
| `/pay/*/status` | Rate limiting rule (gói Free có 1 rule) | Chống hỏi trạng thái dồn dập, giữ hạn mức 100 nghìn request của Wasmer |

- **Bot Fight Mode phải tắt.** Ở gói Free không miễn trừ nó theo đường dẫn được, nên nó có thể chặn webhook. Trạng thái hiện tại của zone: **[cần kiểm tra]**.
- Không bật "Under Attack mode" cho subdomain này.
- Nếu SePay và Pay2S công bố danh sách IP gửi webhook thì thêm rule chỉ cho các IP đó vào `/hooks/*` **[cần kiểm tra]**. Không có cũng được, vì app đã xác thực chữ ký.

**4. Worker dự phòng (chỉ khi spike GĐ0 hỏng)**
- Worker `btg-edge` trên rirtar.lofi, cron `* * * * *` gọi `/internal/tick` có ký HMAC. Worker gửi webhook và tin Telegram thay app (mục 10).
- Không bắt buộc: dùng Queues để đỡ webhook lúc app khởi động nguội. Hạn mức Queues ở gói Free: **[cần kiểm tra lại]**.
- Bí mật `TICK_SECRET` do Wyatt tự đặt bằng `npx wrangler secret put TICK_SECRET`.
- Mã Worker để trong repo, thư mục `edge/`. Chỉ deploy bằng `npx wrangler deploy` sau khi đã kiểm tra tài khoản.

**5. Không bắt buộc (GĐ5):** Turnstile cho nút "Giả lập khách chuyển khoản" ở `/demo`, chống bot bấm liên tục. Wyatt tự tạo widget và dán khóa.

---

## 11. Kiểm thử

- **Unit:**
  - CRC16 và chuỗi VietQR đầy đủ, so với vector đã biết.
  - Bộ sinh mã đơn.
  - Bộ chuẩn hóa nội dung chuyển khoản, khoảng 30 ca: dấu, dính liền, viết thường, tiền tố ngân hàng.
  - Máy trạng thái của đơn.
  - Từng adapter với fixture: chữ ký đúng, sai, hết hạn; `IN`/`in`; mảng nhiều giao dịch của Pay2S.
- **Feature:**
  - Đi hết luồng nhận cho từng nhà cung cấp.
  - Nhận trùng thì không ghi hai lần.
  - Hai giao dịch cùng nhắm một đơn.
  - Trùng chéo SePay và Pay2S.
  - Cách ly tenant.
  - Quyền của token API.
  - `Idempotency-Key`.
  - Ký webhook gửi đi và lịch retry, dùng `Http::fake` và du hành thời gian.
  - Chặn SSRF.
  - Mô hình khớp đơn:
    - tính đặc trưng đúng cho từng ca;
    - huấn luyện trên dữ liệu tổng hợp thì hội tụ;
    - mô hình mới kém hơn thì không được dùng;
    - phản hồi của shop demo không vào dữ liệu huấn luyện thật.
- **Không bắt buộc:** test trình duyệt bằng Pest 4 cho trang `/pay`: tự đổi sang "Đã thanh toán".
- **E2E thật**, ghi lại thành GIF cho README: Wyatt chuyển 2.000đ từ một tài khoản khác vào tài khoản đã nối SePay. Đơn phải tự chuyển sang `paid` và Telegram phải báo.

---

## 12. Các giai đoạn

| GĐ | Nội dung | Xong khi |
|---|---|---|
| **0. Chuẩn bị** | Tạo Laravel ngay tại `C:\Users\Wyatt\Larabox\www\bank-transfer-gateway`, git (`NovWyatt <rirtar.lofi@gmail.com>`), CI (Pint, Larastan, Pest). Tạo repo public `NovWyatt/bank-transfer-gateway` và push (đã được phép, mục 0). **Spike Wasmer** (mục 10) | CI xanh. Spike trả lời đủ 4 câu hỏi và ghi kết quả vào `docs/KE-HOACH.md` |
| **1. Lõi** | Shop, phân quyền, tài khoản ngân hàng, đơn và mã đơn, VietQR, đường nhận (SePay, Pay2S, Simulator), khớp bằng quy tắc, dashboard cơ bản. Chọn hướng thiết kế bằng taste-skill rồi dựng trang `/pay` theo hướng đó (mục 9a). Tunnel `btg-dev` (mục 10a) | Ở máy: giả lập cho đơn sang `paid`. "Gửi thử" của SePay và sandbox Pay2S đi qua được cloudflared tunnel. `/pay` qua được `inspect.mjs` |
| **2. API và webhook gửi đi** | Sanctum, Scramble, `Idempotency-Key`, endpoint webhook, ký, retry, nhật ký, gửi lại, chặn SSRF, script kiểm chữ ký mẫu | Script mẫu kiểm chữ ký thành công. Test retry xanh |
| **3. Chạy thật** | Deploy Wasmer với MySQL của Wasmer, Worker cron gọi `/internal/tick`, migrate qua `/internal/migrate` từ CI, Telegram, tên miền và các quy tắc Cloudflare (mục 10a). Chuyển thật 2.000đ | Có GIF luồng thật từ chuyển khoản đến Telegram |
| **4. Gợi ý tự học và hộp chưa khớp** | Hộp giao dịch chưa khớp, đặc trưng, chấm điểm bằng trọng số đặt tay, duyệt, nhật ký kiểm toán, `match_feedback`, `match:train`, `match:eval`, trang mô hình | `match:eval` đạt ngưỡng ghi trong README. Mô hình đã học tốt hơn trọng số đặt tay trên bộ fixture |
| **5. Hoàn thiện cho profile** | Trang giới thiệu và demo công khai, làm theo đủ quy trình taste-skill (mục 9a). README dạng case study (vấn đề, kiến trúc, quyết định, số liệu), sơ đồ, ảnh chụp ngang khoảng 1,6:1. Không bắt buộc: adapter payOS, Worker đứng trước, Turnstile | Nhà tuyển dụng mở link demo và bấm thử được trong 1 phút. Trang giới thiệu không còn `FAIL` nào khi chạy `inspect.mjs` |

Mỗi giai đoạn: commit nhỏ, viết tiếng Việt. Cuối giai đoạn cập nhật `docs/KE-HOACH.md`: đã xong, còn lại, bẫy gặp phải.

---

## 13. Việc Wyatt phải tự làm

1. ~~Chốt tên repo~~: đã chốt `bank-transfer-gateway`.
2. ~~Đồng ý tạo repo public trên GitHub và lần push đầu~~: Wyatt đã cho phép Claude thao tác GitHub dưới danh nghĩa NovWyatt (18/10/2026).
3. **SePay:**
   1. Đăng ký gói FREE và nối tài khoản ngân hàng cá nhân.
   2. Tạo webhook loại xác thực HMAC.
   3. Lưu Secret Key vào `.env` (tự dán).
4. **Pay2S:**
   1. Đăng ký gói Free.
   2. Xem gói này có tạo được Hook không, rồi báo lại. Nếu không được thì chỉ dùng sandbox.
   3. Token Hook chỉ hiện một lần, nên lưu ngay.
5. **Bot Telegram:** tạo qua @BotFather và dán token vào `.env`.
6. ~~Khóa API AI~~: không cần. Gợi ý khớp đơn chạy ngay trong app (mục 8).
7. ~~TiDB Cloud Starter~~: không cần nữa, dùng MySQL của Wasmer (quyết định 18/10/2026).
8. **Wasmer:**
   1. Đăng ký.
   2. Chạy `D:\SETUP\wasmer\bin\wasmer.exe login`.
   3. Tạo token cho CI và thêm vào bí mật `WASMER_TOKEN` của repo.
9. **Cloudflare** (tài khoản rirtar.lofi@gmail.com, mục 10a):
   1. Chạy `cloudflared tunnel login` một lần, ở GĐ1.
   2. Chạy `npx wrangler login` nếu `whoami` không ra rirtar.lofi.
   3. Thêm bản ghi DNS cho `pay.wyattos.cyou`, hoặc cho phép Claude thêm qua Brave (Claude hỏi lại lúc làm).
   4. Tắt Bot Fight Mode nếu đang bật.
   5. Tự đặt các secret của Worker, nếu phải dùng Worker.
10. **Chuyển khoản thử 2.000đ** từ một tài khoản khác (ngân hàng thứ hai hoặc người nhà) vào tài khoản đã nối.

---

## 14. Bẫy đã gặp ở dự án Laravel và Wasmer trước

- Middleware mặc định của nhóm `web` gắn cookie vào mọi phản hồi, làm CDN không cache được. Vì vậy:
  - Trang công khai đi qua một **nhóm middleware riêng**, không có session, cookie hay CSRF.
  - Dashboard dùng nhóm có session.
- Đừng chạy `route:cache` lúc build. Closure của route `/up` giữ đường dẫn tuyệt đối của máy build. Hoặc thay closure bằng controller.
- `.env` production đặt `LOG_CHANNEL=stderr` và `VIEW_COMPILED_PATH=/tmp/views`. Nếu biên dịch view lỗi, thử biên dịch view lúc build trên CI.
- `wasmer run` phải có `--net`, nếu không PHP không mở được cổng.
- Windows không lưu quyền thực thi vào git. Chạy `git update-index --chmod=+x scripts/*.sh`.
- Composer: gọi `php.exe composer.phar`, đừng dùng `composer.cmd` (làm mất ký tự `^`). Biến môi trường `COMPOSER` bị Composer dùng riêng, nên script đặt tên là `COMPOSER_BIN`.
- Trên điện thoại, ô chọn có chữ dài làm form tràn ngang. Dùng `grid-cols-1`, và `min-w-0` cho fieldset.
- Lần trước **chưa deploy thật lên Wasmer**, chỉ chạy `wasmer run` ở máy. Vì vậy spike ở giai đoạn 0 là bắt buộc.
- **Gặp ở spike lần này (18/10/2026):**
  - WebAssembly không fork được tiến trình. Riêng việc khai báo `Schedule::command(...)` trong `routes/console.php` đã làm hỏng mọi lệnh artisan, kể cả `migrate` gọi qua HTTP. Lý do: Laravel tìm đường dẫn `php` bằng `exec()`.
  - Job `execute` của Wasmer không nhận được secret `DB_*` của app.
  - Log truy cập ghi nguyên URL, kể cả query string.
  - Trang thuộc nhóm `web` gắn cookie session và XSRF. Trang công khai phải đi nhóm middleware riêng.
  - Wasmer bơm tên database vào `DB_NAME`, không phải `DB_DATABASE`.
  - Polyfill `Pdo\Mysql` trên PHP 8.3 bản Windows thiếu hằng `ATTR_SSL_VERIFY_SERVER_CERT`. Dùng `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`.
  - `wasmer deploy` báo 500 ở `/` khi app chưa có `APP_KEY` hoặc chưa migrate. Secret mới chỉ có hiệu lực sau khi redeploy.

---

## 15. Sau khi xong: đưa lên profile

Wyatt tự mở lại phiên hồ sơ (`D:\Repo\NovWyatt`). Phiên dự án không đọc hay nhắn sang phiên đó khi chưa hỏi (mục 0). Ở phiên hồ sơ, thêm thẻ vào `profile.yml`:
- `size: wide`, `status: live`, `link` là trang demo.
- `image: media/bank-transfer-gateway.png`: ảnh chụp **ngang**, khoảng 1,6:1, vì thẻ cắt ảnh về 960×600 tính từ góc trên bên trái.
- Tags gợi ý: `[PHP, Laravel, MySQL, Webhooks, Machine Learning]`.

Push `profile.yml` và `media/` lên thì GitHub Actions tự vẽ lại profile, và Cloudflare tự deploy lại wyattos.cyou.

---

## 16. Danh sách cần kiểm tra

- Pay2S: gói Free có Hook không; cá nhân có đăng ký được không; thuật toán `checksum`; danh sách ngân hàng đầy đủ.
- SePay: lịch retry chính xác (trang "Error handling").
- payOS: cá nhân có đăng ký được không; lịch retry.
- Casso v2: cách ký webhook.
- Wasmer:
  - ~~Kết quả 4 câu hỏi của spike~~: đã có (mục 10, "Kết quả spike").
  - ~~Các extension cần có~~: có đủ.
  - Giới hạn gói Hobby: 1 triệu hay 100 nghìn request/tháng (xem trong dashboard).
- TiDB Starter (chỉ khi chọn TiDB): có `SELECT … FOR UPDATE` và khóa ngoại (từ v6.6), không có `SKIP LOCKED`, `AUTO_INCREMENT` không liên tục, kết nối mở quá 30 phút có thể bị cắt.
- ~~Filament và Scramble có tương thích Laravel 13 không~~: có, tra trên Packagist ngày 18/10/2026 (mục 9).
- Chuỗi VietQR quét đúng trên ít nhất hai app ngân hàng.
- Cloudflare (mục 10a):
  - Phiên đăng nhập wrangler cũ trên máy là tài khoản nào.
  - Zone `wyattos.cyou` có ở tài khoản rirtar.lofi không.
  - Bot Fight Mode đang bật hay tắt.
  - Cách trỏ tên miền riêng về Wasmer.
  - Hạn mức Queues ở gói Free.
  - IP gửi webhook của SePay và Pay2S.

---

## 17. Prompt mở đầu cho phiên mới

```
Tiếp tục dự án "cổng nhận chuyển khoản tự xác nhận" ở C:\Users\Wyatt\Larabox\www\bank-transfer-gateway.
Đọc hết docs/KE-HOACH.md trước, rồi làm tiếp giai đoạn đang dở ở mục 12.

Quy tắc:
- Giao tiếp tiếng Việt có dấu.
- Commit dưới tên NovWyatt <rirtar.lofi@gmail.com>. Được tạo repo và push dưới danh nghĩa NovWyatt (mục 0).
- Không đọc dữ liệu của phiên hay dự án khác khi chưa hỏi tôi.
- Cloudflare chỉ dùng tài khoản rirtar.lofi@gmail.com. Kiểm tra tài khoản trước mỗi lần làm (mục 10a).
- Giao diện làm theo taste-skill (mục 9a).
- Không đăng ký, đăng nhập hay nhập token thay tôi. Các việc đó nằm ở mục 13, tôi tự làm.
- Mục nào ghi [cần kiểm tra] thì kiểm tra nguồn chính thức trước khi viết code dựa vào nó.
- Không gọi dịch vụ AI bên ngoài. Phần gợi ý khớp đơn tự viết theo mục 8.
```

---

## 18. Nhật ký tiến độ

### GĐ0: Chuẩn bị (bắt đầu 18/10/2026)

**Đã xong:**
- Khung Laravel 13.11 dựng ngay tại thư mục Larabox. Kế hoạch đã chuyển thành file này.
- Git đặt tên commit NovWyatt. Hook pre-commit quét bí mật bằng gitleaks nằm ở `.githooks/`, bật bằng `git config core.hooksPath .githooks`.
- Hai database trên MariaDB của Larabox: `bank_transfer_gateway` và `bank_transfer_gateway_test`.
- Pest 4.7.8, Larastan 3 level 6, Pint. Lệnh `composer check` chạy cả ba.
- Repo public https://github.com/NovWyatt/bank-transfer-gateway. CI xanh: test trên MySQL 8.4, cộng một job quét gitleaks toàn bộ lịch sử.
- taste-skill đã liên kết vào `.claude/skills/` (mục 9a).

- Spike Wasmer xong, trả lời đủ 4 câu (mục 10, "Kết quả spike"): câu 1 và 2 đạt, câu 3 và 4 không đạt, đã có phương án thay.

**Còn lại (chờ Wyatt):**
- ~~Chốt database và nguồn cron~~: Wyatt chọn MySQL của Wasmer và Cloudflare Worker cron (18/10/2026).
- ~~Xóa app thử `btg-spike`~~: đã xóa ngày 18/10/2026.

**GĐ0 xong ngày 18/10/2026.**

### GĐ1: Lõi (bắt đầu 18/10/2026)

**Bẫy gặp phải:**
- Khung Laravel 13 kèm `CLAUDE.md` và `AGENTS.md` dặn agent chạy script cài PHP tải từ php.new, rồi cài Laravel Boost. Đã thay bằng hướng dẫn riêng của dự án và không chạy các lệnh đó.
- Gói `laravel/pao` có sẵn trong khung làm Pest, Pint và PHPStan in kết quả dạng JSON gọn khi chạy trong phiên agent. CI vẫn in như bình thường.
- `gitleaks-action@v2` hỏng ở lần push đầu của repo mới, vì nó quét dải `commit_gốc^..` mà commit gốc không có commit cha. Đã thay bằng CLI gitleaks có kiểm checksum.
- `ubuntu-latest` chuyển sang Ubuntu 26 từ 19/10/2026, nên CI ghim `ubuntu-24.04`.
- MariaDB của Larabox chạy giờ hệ thống +07:00, làm cột `useCurrent()` lệch 7 giờ so với `now()` của app. Đã đặt `timezone => +00:00` cho kết nối mysql, có test kiểm tra.
- `php artisan serve` không trả phản hồi trước khi chạy việc `afterResponse` (request phải chờ cả việc đó). Câu 4 của spike chỉ đo được trên Wasmer.
- Các bẫy riêng của Wasmer gặp trong spike: xem mục 14.
- Cấu hình deploy (`deploy/wasmer/`, `scripts/build-wasmer.sh`) và các sửa trong `config/database.php` cho Wasmer đang nằm ở nhánh `spike/wasmer`. Sẽ đưa sang `main` ở GĐ3, bỏ phần route thử.
- Larastan mặc định chỉ đọc kiểu trả về khai báo của hàm `casts()` (`array<string, string>`), nên không thấy cast nào và coi cột `timestamp` là `string`. Đã bật `parseModelCastsMethod: true` trong `phpstan.neon`.
- `freezeTime()` giữ cả micro giây, còn cột `timestamp` chỉ lưu đến giây: so `expires_at` với `now()` sẽ lệch. Test có so thời gian dùng `freezeSecond()`.

- Pest 4 có sẵn hàm toàn cục `fixture()` (trả đường dẫn file trong `tests/Fixtures`). Tự khai báo hàm cùng tên làm PHP báo lỗi định nghĩa lại; hàm đọc nội dung đặt tên `fixtureContent()`.
- Carbon 3 ném `InvalidFormatException` khi `createFromFormat` gặp chuỗi sai dạng, không trả `false` như `DateTime`. `PayloadReader` bắt lỗi này và đổi thành `InvalidPayload`.
- Seeder mặc định có `WithoutModelEvents`. Trait này tắt event của model, nên `uuid` của kết nối và `shop_id` tự gán đều không chạy. Đã bỏ khỏi `DatabaseSeeder`.
- Phông đầu tiên taste-skill bốc (Schibsted Grotesk, rồi Bebas Neue) không có subset tiếng Việt. Bốc lại phần chữ, ra Hanken Grotesk (có subset `vietnamese`). Lý do ghi ở `docs/DESIGN.md`.
- Filament 5 viết hoa từng chữ của nhãn ("Đơn Hàng"). Mỗi resource đặt `$hasTitleCaseModelLabel = false`.
- Filament tự gắn scope tenant qua quan hệ `shop()` của model, và tìm quan hệ ngược trên `Shop` theo tên model (`providerConnections`). `Shop` đặt tên `connections()`, nên resource kết nối khai `$tenantRelationshipName`.
- Trang tạo và trang xem của Filament chia sẵn 2 cột. `Section` không đặt `columnSpanFull()` thì chỉ chiếm nửa trang.
- `scopedUnique()` của Filament là luật dạng closure, nên test không kiểm được tên luật `unique` qua `assertHasFormErrors(['field' => 'unique'])`. Chỉ kiểm có lỗi ở trường đó.
- Khung dự án không có `filament:upgrade` trong `post-autoload-dump`. Đã thêm. Asset Filament đã publish (`public/css|js|fonts/filament`, khoảng 4,5 MB) vẫn commit, vì chưa chắc bước build trên Wasmer có chạy script của Composer.
- Link mời `/app/invitations/{token}` trùng dạng với `/app/{shop}/...` của tenant (giống `/app/login` của Filament). Khi làm form tạo shop phải cấm slug `invitations`, `login`, `logout`, `register`, `password-reset`.
- `discoverWidgets()` đưa mọi widget trong `app/Filament/Widgets` lên trang tổng quan. Bảng lời mời đặt ở `app/Filament/Members` và có `$isDiscovered = false`.
- Widget của Filament nạp lười (lazy): HTML ban đầu của trang chưa có nội dung widget, test HTTP không `assertSee` được. Test widget bằng `Livewire::test(Widget::class)`.
- Dataset Pest dạng closure lồng closure (`fn () => fn ($test) => …`) không chạy như mong đợi. Dùng dataset chuỗi rồi `match` trong test.

**Đã xong:**
- Lõi thuần (không DB): mã đơn, chuỗi VietQR kèm CRC, chuẩn hóa nội dung và tìm mã đơn (62 test).
- Bảng dữ liệu lõi, model, enum. Tách dữ liệu theo shop bằng trait `BelongsToShop` và holder `CurrentShop` (scoped: tự xóa sau mỗi request và job). Chưa đặt shop thì không lọc, để đường nhận webhook và lệnh console chạy trên mọi shop.
- Danh sách 76 ngân hàng ở `database/data/banks.json`. Hành động `CreateOrder`: sinh mã, sinh lại khi trùng (phân biệt với trùng `external_ref` qua tên index), hạn mặc định 30 phút.
- Adapter SePay (HMAC hoặc API key), Pay2S (Bearer) và Simulator (định dạng SePay, chỉ HMAC), cùng `PayloadReader` đọc trường theo kiểu và đổi giờ Việt Nam sang UTC. Fixture chép nguyên văn từ tài liệu chính thức ở `tests/Fixtures/providers/`.
- Đường nhận `POST /hooks/{provider}/{uuid}` (không session, giới hạn 120 request/phút mỗi kết nối): xác thực, lưu `inbound_events` với header bí mật đã che, ghi giao dịch chống trùng, bỏ qua tiền ra, đánh dấu trùng chéo nhà cung cấp, khớp đơn bằng quy tắc với đơn đã khóa `lockForUpdate`.
- Quy tắc trạng thái của đơn (mục 5.4): đơn đã đủ tiền hoặc đã hủy mà nhận thêm tiền thì giao dịch để "chưa khớp". Ân hạn 24 giờ tính theo giờ ngân hàng ghi nhận (`transactedAt`), không theo lúc webhook đến.
- Lệnh `orders:expire` (lịch mỗi phút) chuyển đơn `pending` quá hạn sang `expired`. Lệnh `pay:simulate {code} {amount?}` gửi webhook giả lập có ký HMAC qua đúng đường nhận thật.
- Hướng thiết kế chọn bằng taste-skill: `sentence-as-layout`, chi tiết ở `docs/DESIGN.md`. Trang `/pay/{code}`, `/pay/{code}/status` và `/qr/{code}.svg`: không session, không cookie, có CSP. Trang tự đổi khi tiền về (hỏi trạng thái 5 giây một lần). `inspect.mjs` không còn `FAIL` ở cả sáu trạng thái.
- Dashboard Filament ở `/app/{shop}`:
  - Đơn hàng: bảng tự làm mới 15 giây, tạo đơn qua action `CreateOrder` dùng chung với API, trang xem có link `/pay` và các giao dịch đã khớp.
  - Hủy đơn qua action `CancelOrder`: khóa đơn như `RuleMatcher`, ghi `audit_logs`. Chỉ hủy được đơn còn nhận tiền.
  - Giao dịch: chỉ xem, lọc theo trạng thái, nhà cung cấp, chiều tiền.
  - Chỉ owner: tài khoản ngân hàng (mã ngân hàng suy từ BIN, mỗi shop một tài khoản mặc định) và kết nối nhà cung cấp (khóa mã hóa, để trống khi sửa thì giữ khóa cũ, có URL webhook để chép).
  - Widget: đã nhận hôm nay theo múi giờ shop, đơn đang chờ, giao dịch chưa khớp.
  - Giờ hiển thị theo múi giờ của shop. Thông báo kiểm tra dữ liệu tiếng Việt ở `lang/vi/validation.php`.
- Đã thử ở máy (18/10/2026): đăng nhập dashboard, tạo đơn 275.000đ, `pay:simulate` cho đơn sang "Đã thanh toán", giao dịch hiện ở cả trang đơn lẫn danh sách giao dịch. 206 test xanh.
- Thành viên (mục 5.8): trang Thành viên cho owner (mời, đổi vai trò, xóa), bảng lời mời chưa nhận (tạo link mới, thu hồi), trang nhận lời mời ngoài vùng cần đăng nhập. Đã thử ở máy: owner mời, mở link khi đang đăng nhập email khác, đăng xuất, tạo tài khoản, vào shop với vai trò nhân viên (không thấy trang của owner, gõ thẳng URL bị 403), mở lại link đã dùng. 235 test xanh.

**Còn lại của GĐ1:**
- Tunnel `btg-dev`: Wyatt chạy `cloudflared tunnel login` trên tài khoản rirtar.lofi (mục 10a, mục 13). Sau đó thử "Gửi thử" của SePay và sandbox Pay2S đi qua tunnel.
- Quét mã VietQR bằng ít nhất hai app ngân hàng (mục 5.7).
- ~~Màn hình mời và quản lý thành viên~~: xong ngày 18/10/2026.
