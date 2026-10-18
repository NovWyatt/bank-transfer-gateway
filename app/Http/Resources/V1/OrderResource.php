<?php

namespace App\Http\Resources\V1;

use App\Models\Order;
use App\Payments\Banks\BankDirectory;
use App\Payments\PaymentView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một đơn trong API v1. Tiền là số nguyên VND, giờ là ISO 8601 theo UTC.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $view = PaymentView::for($this->resource, app(BankDirectory::class));

        return [
            /** Mã đơn, cũng là nội dung chuyển khoản. */
            'code' => $this->code,
            'status' => $this->status,
            'amount' => $this->amount,
            'paid_amount' => $this->paid_amount,
            /** Số tiền còn phải chuyển, không âm. */
            'remaining_amount' => $this->remainingAmount(),
            'external_ref' => $this->external_ref,
            'description' => $this->description,
            'customer_name' => $this->customer_name,
            /** Tiền về sau hạn nhưng trong 24 giờ ân hạn. */
            'paid_after_expiry' => $this->paid_after_expiry,
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            /** Trang thanh toán gửi cho khách: có QR, tự đổi khi tiền về. */
            'pay_url' => route('pay.show', $this->code),
            /** Ảnh QR dạng SVG. Trả 410 khi đơn không còn nhận tiền. */
            'qr_svg_url' => route('pay.qr', $this->code),
            /** Chuỗi VietQR với số tiền còn thiếu, null khi đơn không còn nhận tiền. */
            'qr_string' => $view->acceptsTransfer() ? $view->qrPayload() : null,
            'transfer' => [
                'bank_bin' => $view->bankBin,
                'bank' => $view->bankShortName,
                'account_number' => $view->accountNumber,
                'account_name' => $view->accountName,
                'amount' => $view->remaining,
                'memo' => $this->code,
            ],
        ];
    }
}
