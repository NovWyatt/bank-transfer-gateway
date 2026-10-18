<?php

namespace App\Http\Resources\V1;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một giao dịch ngân hàng nhà cung cấp đã báo về.
 *
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            /** Mã giao dịch phía nhà cung cấp. */
            'external_id' => $this->external_id,
            'direction' => $this->direction,
            'amount' => $this->amount,
            /** Nội dung chuyển khoản do khách gõ: không đáng tin, nhớ escape khi hiển thị. */
            'content' => $this->content,
            'reference_code' => $this->reference_code,
            'status' => $this->status,
            /** Mã đơn đã khớp, null khi chưa khớp. */
            'order_code' => $this->order?->code,
            'payer_name' => $this->payer_name,
            'payer_account' => $this->payer_account,
            'account_number' => $this->bankAccount?->account_number,
            'transacted_at' => $this->transacted_at->toIso8601ZuluString(),
            'received_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
