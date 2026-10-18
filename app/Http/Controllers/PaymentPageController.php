<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Payments\Banks\BankDirectory;
use App\Payments\PaymentView;
use App\Payments\VietQr\QrSvg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Trang thanh toán công khai cho khách: không session, không cookie, không đăng nhập.
 * Ai có mã đơn thì xem được, như một hóa đơn giấy.
 */
final class PaymentPageController
{
    public function __construct(
        private readonly BankDirectory $banks,
    ) {}

    public function show(string $code): Response
    {
        $pay = $this->view($code);

        return response()
            ->view('pay.show', ['pay' => $pay])
            ->header('Cache-Control', 'no-store');
    }

    public function status(string $code): JsonResponse
    {
        return response()
            ->json($this->view($code)->toStatusArray())
            ->header('Cache-Control', 'no-store');
    }

    public function qr(string $code): Response
    {
        $pay = $this->view($code);

        // Hết hạn hay đã đủ tiền thì không phát QR nữa, để khách không chuyển thêm.
        abort_unless($pay->acceptsTransfer(), 410);

        // URL ảnh có kèm số tiền còn thiếu (?a=...), nên cache ngắn vẫn an toàn khi khách trả thiếu.
        return response(QrSvg::render($pay->qrPayload()), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function view(string $code): PaymentView
    {
        $order = Order::query()
            ->with(['bankAccount', 'shop'])
            ->where('code', strtoupper($code))
            ->firstOrFail();

        return PaymentView::for($order, $this->banks);
    }
}
