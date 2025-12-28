<?php

namespace App\Filament\Resources\ElonResource\Pages;

use App\Filament\Resources\ElonResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditElon extends EditRecord
{
    protected static string $resource = ElonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
