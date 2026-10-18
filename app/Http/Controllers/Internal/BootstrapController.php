<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Members\InviteMember;
use App\Enums\ShopRole;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tạo shop đầu tiên trên một bản cài mới, kèm lời mời owner. Chỉ chạy được khi chưa có shop thật nào.
 *
 * App chưa có trang đăng ký, và trên Wasmer không có shell để chạy seeder. Mật khẩu không đi qua đây:
 * người nhận mở link mời rồi tự đặt mật khẩu ở trang tham gia.
 */
class BootstrapController
{
    /**
     * Slug nằm ngay sau /app/, nên không được trùng các đường của panel.
     */
    private const RESERVED_SLUGS = ['invitations', 'login', 'logout', 'password-reset', 'email-verification', 'profile', 'new'];

    public function __invoke(Request $request, InviteMember $invite): JsonResponse
    {
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(self::RESERVED_SLUGS)],
            'owner_email' => ['required', 'email', 'max:254'],
        ]);

        $issued = DB::transaction(function () use ($data, $invite) {
            // Khóa để hai lần gọi song song không cùng tạo shop đầu tiên.
            if (Shop::query()->where('is_demo', false)->lockForUpdate()->exists()) {
                return null;
            }

            $shop = Shop::query()->create([
                'name' => $data['shop_name'],
                'slug' => $data['slug'],
            ]);

            return $invite->handle($shop, $data['owner_email'], ShopRole::Owner, by: null, ip: request()->ip());
        });

        if ($issued === null) {
            return response()->json([
                'message' => 'Đã có shop. Mời thêm người từ trang Thành viên của dashboard.',
                'code' => 'already_bootstrapped',
            ], 409);
        }

        return response()->json([
            'shop' => $data['slug'],
            'invitation_url' => $issued->url(),
            'expires_at' => $issued->invitation->expires_at->toIso8601String(),
        ], 201);
    }
}
