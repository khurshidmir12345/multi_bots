<?php

namespace App\Filament\Resources\ElonResource\Pages;

use App\Filament\Resources\ElonResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;
use Filament\Notifications\Notification;

class ListElons extends ListRecords
{
    protected static string $resource = ElonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('sendToChannel')
                ->label('Kanalga Yuborish')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Elonlarni Kanalga Yuborish')
                ->modalDescription('Tasdiqlangan elonlarni kanalga yuborish uchun command ishga tushiriladi. Davom etasizmi?')
                ->action(function () {
                    try {
                        Artisan::call('elons:send-to-channel');
                        $output = Artisan::output();
                        
                        Notification::make()
                            ->title('Muvaffaqiyatli')
                            ->body('Command muvaffaqiyatli ishga tushirildi! ' . $output)
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Xatolik')
                            ->body('Command ishga tushirilmadi: ' . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
