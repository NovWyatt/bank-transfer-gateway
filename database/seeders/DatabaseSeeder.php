<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Không dùng WithoutModelEvents: uuid của kết nối và shop_id tự gán đều chạy qua sự kiện creating.
     */
    public function run(): void
    {
        $this->call(LocalShopSeeder::class);
    }
}
