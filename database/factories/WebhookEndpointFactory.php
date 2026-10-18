<?php

namespace Database\Factories;

use App\Enums\WebhookEventType;
use App\Models\Shop;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'url' => 'https://shop.example/webhooks/gateway',
            'description' => null,
            'events' => array_keys(WebhookEventType::options()),
            'active' => true,
        ];
    }

    /** @param  list<WebhookEventType>  $types */
    public function only(array $types): static
    {
        return $this->state(['events' => array_map(fn (WebhookEventType $type): string => $type->value, $types)]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
