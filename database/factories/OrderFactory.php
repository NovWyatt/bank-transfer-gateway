<?php

namespace Database\Factories;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\Shop;
use App\Payments\OrderCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'bank_account_id' => fn (array $attributes) => BankAccount::factory()->default()->create([
                'shop_id' => $attributes['shop_id'],
            ]),
            'code' => OrderCode::generate(),
            'external_ref' => null,
            'amount' => fake()->numberBetween(10, 500) * 1000,
            'paid_amount' => 0,
            'status' => OrderStatus::Pending,
            'description' => null,
            'customer_name' => null,
            'expires_at' => now()->addMinutes(30),
            'paid_at' => null,
            'paid_after_expiry' => false,
            'created_via' => OrderSource::Dashboard,
            'metadata' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state([
            'status' => OrderStatus::Expired,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
