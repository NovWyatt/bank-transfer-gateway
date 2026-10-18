<?php

namespace App\Filament\Resources\BankAccounts\Pages;

use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Models\BankAccount;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBankAccounts extends ManageRecords
{
    protected static string $resource = BankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm tài khoản')
                // Tài khoản đầu tiên của shop luôn là mặc định, để tạo đơn được ngay.
                ->mutateDataUsing(function (array $data): array {
                    if (BankAccount::query()->where('is_default', true)->doesntExist()) {
                        $data['is_default'] = true;
                    }

                    return $data;
                }),
        ];
    }
}
