<?php

namespace App\Filament\Resources\NetworkResource\Pages;

use App\Filament\Resources\NetworkResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNetwork extends CreateRecord
{
    protected static string $resource = NetworkResource::class;

    protected function getRedirectUrl(): string
    {
        return NetworkResource::getUrl('map', ['record' => $this->record]);
    }
}
