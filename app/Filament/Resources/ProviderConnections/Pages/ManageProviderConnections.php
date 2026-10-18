<?php

namespace App\Filament\Resources\ProviderConnections\Pages;

use App\Filament\Resources\ProviderConnections\ProviderConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageProviderConnections extends ManageRecords
{
    protected static string $resource = ProviderConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm kết nối'),
        ];
    }
}
