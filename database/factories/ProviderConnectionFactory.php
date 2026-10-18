<?php

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Enums\Provider;
use App\Models\ProviderConnection;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProviderConnection>
 */
class ProviderConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'provider' => Provider::SePay,
            'bank_account_id' => null,
            'label' => null,
            'credentials' => ['secret' => Str::random(40)],
            'status' => ConnectionStatus::Active,
        ];
    }

    public function provider(Provider $provider): static
    {
        return $this->state(['provider' => $provider]);
    }

    public function paused(): static
    {
        return $this->state(['status' => ConnectionStatus::Paused]);
    }
}
