<?php

namespace App\Http\Requests\V1;

use App\Models\Shop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    /** Chuỗi VietQR cho trường số tiền tối đa 13 ký tự. */
    public const MAX_AMOUNT = 9_999_999_999_999;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Shop $shop */
        $shop = $this->user('sanctum');

        return [
            /** Số tiền, số nguyên VND. */
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            /** Mã đơn bên hệ thống của shop, duy nhất trong shop. */
            'external_ref' => [
                'nullable', 'string', 'max:100',
                Rule::unique('orders', 'external_ref')->where('shop_id', $shop->getKey()),
            ],
            /** Khách thấy dòng này trên trang thanh toán. */
            'description' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            /** Hết hạn sau bao nhiêu phút, mặc định 30. */
            'expires_in' => ['nullable', 'integer', 'min:5', 'max:10080'],
        ];
    }
}
