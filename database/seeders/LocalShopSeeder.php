<?php

namespace Database\Seeders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\Provider;
use App\Enums\ShopRole;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\ProviderConnection;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dữ liệu cho máy phát triển: một shop mẫu và sáu đơn, mỗi đơn một trạng thái,
 * để xem trang /pay ở mọi trạng thái. Không dùng cho production.
 *
 * Đăng nhập dashboard: owner@example.test, mật khẩu "password" (mặc định của UserFactory).
 */
class LocalShopSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::factory()->create([
            'name' => 'Chủ shop mẫu',
            'email' => 'owner@example.test',
        ]);

        $shop = Shop::factory()->withMember($owner, ShopRole::Owner)->create([
            'name' => 'Tiệm gốm Nhà Mây',
            'slug' => 'tiem-gom-nha-may',
            'order_prefix' => 'DH',
        ]);

        // Số tài khoản lấy từ ví dụ trong tài liệu SePay; tên chủ tài khoản là mẫu.
        $account = BankAccount::factory()->default()->for($shop)->create([
            'bank_bin' => '970436',
            'bank_code' => 'VCB',
            'account_number' => '1017588888',
            'account_name' => 'NGUYEN VAN A',
        ]);

        ProviderConnection::factory()->for($shop)->provider(Provider::Simulator)->create([
            'label' => 'Bộ giả lập trên máy',
            'credentials' => ['secret' => 'local-simulator-secret'],
        ]);

        $orders = [
            ['DH7K3QXM', OrderStatus::Pending, 150_000, 0, now()->addMinutes(30), 'Bình gốm men lam'],
            ['DH4P8RTV', OrderStatus::PartiallyPaid, 150_000, 100_000, now()->addMinutes(25), 'Bộ chén trà 6 chiếc'],
            ['DH9W2KMZ', OrderStatus::Paid, 2_450_000, 2_450_000, now()->addMinutes(20), 'Chậu gốm trồng cây cỡ lớn'],
            ['DH5CN6XQ', OrderStatus::Overpaid, 150_000, 200_000, now()->addMinutes(20), 'Đĩa gốm vẽ tay'],
            ['DH3HTB8Y', OrderStatus::Expired, 320_000, 0, now()->subHour(), 'Lọ hoa men rạn'],
            ['DH6GSF2A', OrderStatus::Cancelled, 90_000, 0, now()->addMinutes(10), 'Muỗng gốm'],
        ];

        foreach ($orders as [$code, $status, $amount, $paid, $expiresAt, $description]) {
            Order::factory()->for($shop)->create([
                'bank_account_id' => $account->id,
                'code' => $code,
                'status' => $status,
                'amount' => $amount,
                'paid_amount' => $paid,
                'expires_at' => $expiresAt,
                'paid_at' => $status->isSettled() ? now()->subMinutes(3) : null,
                'description' => $description,
                'created_via' => OrderSource::Dashboard,
            ]);
        }
    }
}
