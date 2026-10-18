<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            // Vietcombank: BIN 970436, mã VCB.
            'bank_bin' => '970436',
            'bank_code' => 'VCB',
            'account_number' => fake()->unique()->numerify('10########'),
            'account_name' => 'NGUYEN VAN A',
            'label' => null,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
