<?php

namespace Database\Factories;

use App\Enums\ShopRole;
use App\Models\BankAccount;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'order_prefix' => 'DH',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'settings' => null,
            'is_demo' => false,
        ];
    }

    /** Kèm một tài khoản ngân hàng mặc định. */
    public function withBankAccount(): static
    {
        return $this->has(BankAccount::factory()->state(['is_default' => true]), 'bankAccounts');
    }

    public function withMember(User $user, ShopRole $role = ShopRole::Owner): static
    {
        return $this->hasAttached($user, ['role' => $role->value], 'members');
    }
}
