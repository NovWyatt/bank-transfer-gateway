<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderSource: string implements HasLabel
{
    case Dashboard = 'dashboard';
    case Api = 'api';
    case Demo = 'demo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Api => 'API',
            self::Demo => 'Demo',
        };
    }
}
