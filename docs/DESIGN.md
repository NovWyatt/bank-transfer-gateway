# Thiết kế giao diện

Ghi các quyết định giao diện và lý do. Quy trình theo taste-skill, mục 9a của `docs/KE-HOACH.md`.

## Hướng thiết kế (chốt ngày 18/10/2026, dùng chung cho `/pay` và trang giới thiệu GĐ5)

**Cách bốc:** `draw.mjs --sector finance --sections how-it-works,showcase,list,proof,faq,cta,footer --have product-ui`, seed `245136847`.

**Ba hướng được bốc:**
- `one-motif`: một hình của chủ thể chạy khắp trang.
- `sentence-as-layout`: câu là bố cục.
- `quiet-instrument`: trang nói khẽ, cột hẹp.

**Đã chọn: `sentence-as-layout`.** Cả sản phẩm xoay quanh một câu khách phải chép đúng vào app ngân hàng, "chuyển 150.000đ, ghi DH7K3QXM". Đặt mã đơn vào giữa câu, đúng chỗ một từ, là cho thấy chính cơ chế khớp đơn.

**Bỏ `one-motif`:** lấy ô định vị của QR làm họa tiết không qua phép thử đối thủ. Cổng VietQR nào cũng có ô đó.

**Câu ý tưởng:** câu hướng dẫn chuyển khoản, với mã đơn khách phải chép vào ô nội dung, trở thành câu ở cỡ poster. Ô mã đơn nằm đúng chỗ một từ, và là nút chép thật.

**Thứ "sống" trên trang:** trang chờ tiền về. Khi webhook khớp đơn, cả nền chuyển sang bạc hà và câu tự đổi thành "Đã nhận 150.000đ cho đơn DH7K3QXM", không cần tải lại.

### Phông: Hanken Grotesk

- Bốc lại slot phông 2 lần, cả hai vì phông không có bộ ký tự `vietnamese` trên Google Fonts:
  - Schibsted Grotesk: chỉ có latin và latin-ext.
  - Bebas Neue (đi cặp EB Garamond): Bebas Neue chỉ có latin và latin-ext.
- Hanken Grotesk có `vietnamese`. Dùng hai độ đậm: 400 cho chữ thường, 500 cho tiêu đề và chữ chức năng.
- Tự host ở `public/fonts/hanken-grotesk/`, ba subset `vietnamese`, `latin-ext` và `latin`, kèm giấy phép OFL. Trang không gọi sang Google.
- **Chỉnh cho tiếng Việt:** leading tiêu đề là 1.0, không phải 0.9 như bộ phông gốc. Chữ hoa có hai tầng dấu (Ế, Ộ) sẽ đụng dòng trên.
- Dòng bị che khi chạy hiệu ứng có thêm `padding` trên và dưới, để dấu không bị cắt.

### Màu: xám và bạc hà

| Vai trò | Màu |
|---|---|
| Nền | `#ffffff` |
| Bề mặt (ô QR, nút Chép, nền khi hết hạn) | `#f3f3f3` |
| Chữ | `#232323` |
| Chữ phụ | `#6e6e6e` |
| Nhấn và nền khi đã nhận tiền | `#a1ffcb` (bạc hà) |

- Bạc hà là màu của khoảnh khắc "đã khớp". Nó chỉ xuất hiện ở ô mã đơn, tức hành động chính: chép nội dung. Khi tiền về, nó phủ cả nền.
- Không có ảnh tiền giấy, không giống nhận diện của ngân hàng, SePay hay Pay2S.

## Trang thanh toán `/pay/{code}`

- **Câu chính:** hai dòng.
  - Dòng 1: số tiền hoặc trạng thái.
  - Dòng 2: chữ ngắn và ô mã đơn.
  - Cỡ chữ chia theo độ dài dòng đầu, để luôn vừa hai dòng ở mọi độ rộng:
    - Từ 15 ký tự trở xuống: cỡ lớn.
    - Dài hơn: cỡ vừa. Dòng dài nhất có thể là "Còn thiếu 10.000.000đ".
- **Thứ tự trên điện thoại:** câu, rồi các dòng có nút Chép, rồi QR. Khách thường chuyển bằng app ngân hàng trên chính điện thoại đó, nên không quét được QR trên màn hình của mình.
- **Trên máy tính:** QR ở cột phải, ngay màn hình đầu, để quét bằng điện thoại.
- **Trạng thái cuối** (đã trả, trả dư, hết hạn, đã hủy): tấm tóm tắt nằm đúng chỗ QR đã đứng, để khách đối chiếu số tiền và tài khoản nhận.
- **Không session, không cookie:** chỉ JavaScript của chính site, theo CSP `default-src 'self'`.
- **Không có JavaScript:** trang tự làm mới 15 giây một lần.

### Kết quả `inspect.mjs` (18/10/2026)

- Chạy ở 1440, 768 và 390 cho cả sáu trạng thái: **0 FAIL**.
- Miễn một luật: `--allow few-pictures`. Luật "ít nhất 4 ảnh thật" dành cho trang giới thiệu. Trên trang thanh toán, ảnh trang trí làm khách phân tâm khỏi số tiền và mã đơn, lại tải chậm hơn trên mạng di động. Trang giới thiệu GĐ5 vẫn phải đạt luật này.

**Các WARN còn lại và lý do giữ:**

| WARN | Lý do giữ |
|---|---|
| `no-footage` | Video lặp trên trang thanh toán chỉ làm phân tâm. |
| `still-page` | 9 đến 12 phần tử chuyển động, dưới mức 16. Mỗi phần đều có hiệu ứng vào riêng. Trang thanh toán cần yên để đọc số. Hiệu ứng lớn là lúc cả nền đổi màu khi tiền về. |
| `first-action` (chỉ ở trạng thái cuối) | Đơn đã xong hoặc đã đóng thì không còn việc gì để bấm. |

**Vòng xem lại tiếp theo (GĐ5):**
- Nửa dưới bên trái của trạng thái đã trả trên máy tính đang là khoảng nghỉ duy nhất.
- Chữ trong tấm biên nhận có thể lớn hơn.

## Trang đã tham khảo

Chưa tham khảo trang ngoài. Hướng, phông và màu đều do `draw.mjs` bốc từ thư viện của taste-skill.
