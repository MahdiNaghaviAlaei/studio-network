<?php

namespace App\Filament\Resources\EquipmentResource\Pages;

use App\Filament\Resources\EquipmentResource;
use Filament\Resources\Pages\EditRecord;

class EditEquipment extends EditRecord
{
    protected static string $resource = EquipmentResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['data'] = array_replace($this->record->data ?? [], $data['data'] ?? []);

        return $data;
    }
}
