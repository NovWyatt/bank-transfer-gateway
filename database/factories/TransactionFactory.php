<?php

namespace Database\Factories;

use App\Enums\Provider;
use App\Enums\TransactionStatus;
use App\Enums\TransferDirection;
use App\Models\Shop;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'provider_connection_id' => null,
            'inbound_event_id' => null,
            'provider' => Provider::Simulator,
            'external_id' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'bank_account_id' => null,
            'direction' => TransferDirection::In,
            'amount' => fake()->numberBetween(10, 500) * 1000,
            'content' => 'chuyen tien',
            'reference_code' => fake()->bothify('FT26###########'),
            'provider_code' => null,
            'transacted_at' => now(),
            'payer_name' => null,
            'payer_account' => null,
            'raw' => [],
            'fingerprint' => null,
            'status' => TransactionStatus::Unmatched,
        ];
    }
}
