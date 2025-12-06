<?php

namespace App\Filament\Resources\BotUserResource\Pages;

use App\Filament\Resources\BotUserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBotUsers extends ListRecords
{
    protected static string $resource = BotUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Foydalanuvchilar avtomatik yaratiladi, qo'lda yaratish kerak emas
        ];
    }
}
