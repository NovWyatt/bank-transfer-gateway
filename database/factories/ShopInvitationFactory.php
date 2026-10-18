<?php

namespace Database\Factories;

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\ShopInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShopInvitation>
 */
class ShopInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => ShopRole::Staff,
            'token_hash' => ShopInvitation::hashToken(Str::random(48)),
            'invited_by' => null,
            'expires_at' => now()->addDays(ShopInvitation::TTL_DAYS),
            'accepted_at' => null,
            'accepted_by' => null,
        ];
    }

    /** Lời mời có token biết trước, để test mở được link. */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => ShopInvitation::hashToken($token)]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
