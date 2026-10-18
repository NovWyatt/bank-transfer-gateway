<?php

namespace App\Payments\Providers;

use App\Enums\Provider;
use InvalidArgumentException;

final class AdapterRegistry
{
    public function for(Provider $provider): ProviderAdapter
    {
        return match ($provider) {
            Provider::SePay => new SePayAdapter,
            Provider::Pay2S => new Pay2SAdapter,
            Provider::Simulator => new SimulatorAdapter,
            Provider::PayOS => throw new InvalidArgumentException('Chưa hỗ trợ payOS (giai đoạn 5).'),
        };
    }
}
