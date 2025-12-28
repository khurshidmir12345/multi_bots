<?php

namespace App\Filament\Resources\ElonResource\Pages;

use App\Filament\Resources\ElonResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Support\Facades\Storage;

class ViewElon extends ViewRecord
{
    protected static string $resource = ElonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Foydalanuvchi')
                    ->schema([
                        Infolists\Components\TextEntry::make('elonUser.name')
                            ->label('Ism'),
                        Infolists\Components\TextEntry::make('elonUser.user_name')
                            ->label('Username')
                            ->formatStateUsing(fn ($state) => $state ? '@' . $state : '-'),
                        Infolists\Components\TextEntry::make('elonUser.chat_id')
                            ->label('Chat ID'),
                    ])
                    ->columns(3),
                
                Infolists\Components\Section::make('Mashina ma\'lumotlari')
                    ->schema([
                        Infolists\Components\TextEntry::make('modeli')
                            ->label('Model'),
                        Infolists\Components\TextEntry::make('pozitsiyasi')
                            ->label('Pozitsiya'),
                        Infolists\Components\TextEntry::make('rangi')
                            ->label('Rang'),
                        Infolists\Components\TextEntry::make('kraskasi')
                            ->label('Kraskasi'),
                        Infolists\Components\TextEntry::make('yili')
                            ->label('Yil'),
                        Infolists\Components\TextEntry::make('yurgani')
                            ->label('Yurgani')
                            ->formatStateUsing(fn ($state) => $state ? number_format($state, 0, '.', ' ') . ' km' : '-'),
                        Infolists\Components\TextEntry::make('yoqilgisi')
                            ->label('Yoqilg\'i'),
                    ])
                    ->columns(2),
                
                Infolists\Components\Section::make('Narx va aloqa')
                    ->schema([
                        Infolists\Components\TextEntry::make('narxi')
                            ->label('Narx')
                            ->formatStateUsing(function ($state, $record) {
                                if (!$state) return '-';
                                $currency = $record->currency === 'dollar' ? '$' : '';
                                return $currency . number_format($state, 0, '.', ' ');
                            }),
                        Infolists\Components\TextEntry::make('currency')
                            ->label('Valyuta')
                            ->formatStateUsing(fn ($state) => $state === 'dollar' ? 'Dollar' : 'So\'m'),
                        Infolists\Components\TextEntry::make('tel_1')
                            ->label('Telefon 1'),
                        Infolists\Components\TextEntry::make('tel_2')
                            ->label('Telefon 2')
                            ->formatStateUsing(fn ($state) => $state ?: '-'),
                        Infolists\Components\TextEntry::make('manzil')
                            ->label('Manzil')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                
                Infolists\Components\Section::make('Rasmlar')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('images')
                            ->label('')
                            ->schema([
                                Infolists\Components\ImageEntry::make('local_path')
                                    ->label('')
                                    ->height(200)
                                    ->width(200)
                                    ->formatStateUsing(function ($state, $record) {
                                        // Avval local_path'ni tekshirish
                                        if ($state && Storage::disk('public')->exists($state)) {
                                            return Storage::disk('public')->url($state);
                                        }
                                        // Keyin image_url'ni ishlatish
                                        if ($record->image_url) {
                                            return $record->image_url;
                                        }
                                        return null;
                                    }),
                            ])
                            ->columns(3)
                            ->grid(3),
                    ])
                    ->visible(fn ($record) => $record->images()->count() > 0),
                
                Infolists\Components\Section::make('Holat')
                    ->schema([
                        Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(function ($state) {
                                return match($state) {
                                    \App\Models\Elon::STATUS_ENDED => 'Tugatilgan',
                                    \App\Models\Elon::STATUS_ACCEPTED_USER => 'Foydalanuvchi qabul qildi',
                                    \App\Models\Elon::STATUS_SENDED_TO_ADMIN => 'Admin\'ga yuborilgan',
                                    \App\Models\Elon::STATUS_ACCEPTED_ADMIN => 'Admin qabul qildi',
                                    \App\Models\Elon::STATUS_COMPLATED => 'Kanalga chiqarilgan',
                                    default => $state,
                                };
                            })
                            ->color(function ($state) {
                                return match($state) {
                                    \App\Models\Elon::STATUS_COMPLATED => 'success',
                                    \App\Models\Elon::STATUS_ACCEPTED_ADMIN => 'info',
                                    \App\Models\Elon::STATUS_SENDED_TO_ADMIN => 'warning',
                                    \App\Models\Elon::STATUS_ENDED => 'danger',
                                    default => 'gray',
                                };
                            }),
                        Infolists\Components\IconEntry::make('cancelled_from_admin')
                            ->label('Admin tomonidan bekor qilingan')
                            ->boolean(),
                        Infolists\Components\IconEntry::make('cancelled_from_user')
                            ->label('Foydalanuvchi tomonidan bekor qilingan')
                            ->boolean(),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Yaratilgan')
                            ->dateTime('d.m.Y H:i'),
                        Infolists\Components\TextEntry::make('updated_at')
                            ->label('Yangilangan')
                            ->dateTime('d.m.Y H:i'),
                    ])
                    ->columns(2),
            ]);
    }
}
