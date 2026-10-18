@php
    use App\Payments\PaymentView;
    use App\Support\Vnd;

    /** @var PaymentView $pay */
    [$line1, $line2] = match ($pay->state) {
        PaymentView::WAITING => ['Chuyển '.Vnd::format($pay->remaining), 'ghi'],
        PaymentView::PARTIAL => ['Còn thiếu '.Vnd::format($pay->remaining), 'ghi'],
        PaymentView::PAID => ['Đã nhận '.Vnd::format($pay->paidAmount), 'cho đơn'],
        PaymentView::OVERPAID => ['Đã nhận '.Vnd::format($pay->paidAmount), 'cho đơn'],
        PaymentView::EXPIRED => ['Đơn đã hết hạn', 'mã'],
        PaymentView::CANCELLED => ['Đơn đã bị hủy', 'mã'],
    };

    $lead = match ($pay->state) {
        PaymentView::WAITING => 'Mở app ngân hàng, chuyển đúng số tiền và ghi đúng nội dung này. Đơn tự xác nhận khi tiền về.',
        PaymentView::PARTIAL => 'Đã nhận '.Vnd::format($pay->paidAmount).' trên '.Vnd::format($pay->amount).'. Chuyển nốt phần còn thiếu với cùng nội dung.',
        PaymentView::PAID => 'Shop đã nhận đủ tiền lúc '.$pay->localTime($pay->paidAt ?? now()->toImmutable()).'. Bạn có thể đóng trang này.',
        PaymentView::OVERPAID => 'Dư '.Vnd::format($pay->paidAmount - $pay->amount).' so với giá đơn '.Vnd::format($pay->amount).'. Shop sẽ liên hệ để trả lại phần dư.',
        PaymentView::EXPIRED => $pay->paidAmount > 0
            ? 'Hết hạn lúc '.$pay->localTime($pay->expiresAt).' khi mới nhận '.Vnd::format($pay->paidAmount).' trên '.Vnd::format($pay->amount).'. Đừng chuyển thêm, shop sẽ liên hệ với bạn.'
            : 'Hết hạn lúc '.$pay->localTime($pay->expiresAt).'. Đừng chuyển thêm. Nếu bạn đã chuyển trước đó, tiền vẫn được ghi nhận trong 24 giờ.',
        PaymentView::CANCELLED => 'Đừng chuyển khoản cho đơn này. Nếu bạn đã chuyển, hãy liên hệ shop.',
    };

    // Dòng đầu từ 15 ký tự trở xuống dùng cỡ lớn, dài hơn dùng cỡ vừa (xem pay.css).
    $fit = mb_strlen($line1) <= 15 ? "short" : "long";

    $title = $line1.' '.$line2.' '.$pay->code;
@endphp
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <title>{{ $title }} · {{ $pay->shopName }}</title>
    <link rel="preload" href="{{ asset('fonts/hanken-grotesk/hanken-grotesk-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/hanken-grotesk/hanken-grotesk-vietnamese.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/pay.css') }}?v={{ filemtime(public_path('css/pay.css')) }}">
    <script src="{{ asset('js/pay.js') }}?v={{ filemtime(public_path('js/pay.js')) }}" defer></script>
    @if ($pay->acceptsTransfer())
        {{-- Không có JavaScript: tự làm mới để thấy trạng thái mới. --}}
        <noscript><meta http-equiv="refresh" content="15"></noscript>
    @endif
</head>
<body data-state="{{ $pay->state }}">
<main
    class="pay"
    data-pay
    data-state="{{ $pay->state }}"
    data-signature="{{ $pay->signature() }}"
    data-status-url="{{ route('pay.status', $pay->code) }}"
    data-expires="{{ $pay->expiresAt->toIso8601ZuluString() }}"
    data-open="{{ $pay->acceptsTransfer() ? '1' : '0' }}"
>
    <header class="bar">
        <p class="shop">{{ $pay->shopName }}</p>
        @if ($pay->acceptsTransfer())
            <p class="timer">
                <span data-countdown>Hạn {{ $pay->localTime($pay->expiresAt) }}</span>
            </p>
        @endif
    </header>

    <section class="hero" aria-labelledby="sentence">
        <h1 class="sentence" id="sentence" data-fit="{{ $fit }}">
            <span class="line"><span class="line-inner">{{ $line1 }}</span></span>
            <span class="line"><span class="line-inner">{{ $line2 }}
                @if ($pay->acceptsTransfer())
                    <button type="button" class="code" data-ui data-copy="{{ $pay->code }}" aria-label="Chép nội dung {{ $pay->code }}">{{ $pay->code }}</button>
                @else
                    <span class="code" data-ui>{{ $pay->code }}</span>
                @endif
            </span></span>
        </h1>
        <p class="lead">{{ $lead }}</p>
        @if ($pay->description)
            <p class="note">Đơn: {{ $pay->description }}</p>
        @endif
    </section>

    @if ($pay->acceptsTransfer())
        <section class="transfer" aria-label="Thông tin chuyển khoản">
            <dl class="details">
                <div class="row">
                    <dt>Ngân hàng</dt>
                    <dd>
                        <span class="value">{{ $pay->bankShortName }}</span>
                        <span class="sub">{{ $pay->bankName }}</span>
                    </dd>
                </div>
                <div class="row">
                    <dt>Số tài khoản</dt>
                    <dd>
                        <span class="value num">{{ $pay->accountNumber }}</span>
                        <button type="button" class="copy" data-copy="{{ $pay->accountNumber }}">Chép</button>
                    </dd>
                </div>
                <div class="row">
                    <dt>Chủ tài khoản</dt>
                    <dd><span class="value">{{ $pay->accountName }}</span></dd>
                </div>
                <div class="row">
                    <dt>Số tiền</dt>
                    <dd>
                        <span class="value num">{{ Vnd::format($pay->remaining) }}</span>
                        <button type="button" class="copy" data-copy="{{ $pay->remaining }}">Chép</button>
                    </dd>
                </div>
                <div class="row">
                    <dt>Nội dung</dt>
                    <dd>
                        <span class="value num">{{ $pay->code }}</span>
                        <button type="button" class="copy" data-copy="{{ $pay->code }}">Chép</button>
                    </dd>
                </div>
            </dl>
        </section>

        <figure class="qr">
            <img
                src="{{ route('pay.qr', $pay->code) }}?a={{ $pay->remaining }}"
                width="320" height="320"
                alt="Mã VietQR: chuyển {{ Vnd::format($pay->remaining) }} tới {{ $pay->bankShortName }} {{ $pay->accountNumber }}, nội dung {{ $pay->code }}"
            >
            <figcaption>Quét bằng app ngân hàng trên điện thoại khác. Đang chuyển bằng chính điện thoại này? Nhấn giữ ảnh để lưu, rồi chọn ảnh trong mục quét QR của app.</figcaption>
        </figure>
    @else
        {{-- Đơn không còn nhận tiền: tấm tóm tắt để khách đối chiếu, nằm đúng chỗ QR đã đứng. --}}
        <section class="receipt" aria-label="{{ $pay->isSettled() ? "Biên nhận" : "Tóm tắt đơn" }}">
            <dl class="details">
                @if (! $pay->isSettled())
                    <div class="row">
                        <dt>Giá đơn</dt>
                        <dd><span class="value num">{{ Vnd::format($pay->amount) }}</span></dd>
                    </div>
                @endif
                <div class="row">
                    <dt>Đã nhận</dt>
                    <dd><span class="value num">{{ Vnd::format($pay->paidAmount) }}</span></dd>
                </div>
                @if ($pay->isSettled())
                    <div class="row">
                        <dt>Lúc</dt>
                        <dd><span class="value num">{{ $pay->localTime($pay->paidAt ?? now()->toImmutable()) }}</span></dd>
                    </div>
                @elseif ($pay->state === PaymentView::EXPIRED)
                    <div class="row">
                        <dt>Hết hạn lúc</dt>
                        <dd><span class="value num">{{ $pay->localTime($pay->expiresAt) }}</span></dd>
                    </div>
                @endif
                <div class="row">
                    <dt>{{ $pay->isSettled() ? "Vào tài khoản" : "Tài khoản của shop" }}</dt>
                    <dd>
                        <span class="value num">{{ $pay->bankShortName }} {{ $pay->accountNumber }}</span>
                        <span class="sub">{{ $pay->accountName }}</span>
                    </dd>
                </div>
            </dl>
        </section>
    @endif

    <footer class="foot">
        @if ($pay->acceptsTransfer())
            <p class="status" data-live aria-live="polite">Đang chờ tiền về. Trang tự cập nhật, không cần tải lại.</p>
        @endif
        <p class="small">Tiền chuyển thẳng vào tài khoản của {{ $pay->shopName }}. Cổng này chỉ đọc biến động số dư để xác nhận đơn, không giữ tiền của ai.</p>
    </footer>
</main>
</body>
</html>
