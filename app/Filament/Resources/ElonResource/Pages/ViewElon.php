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
                                Infolists\Components\TextEntry::make('image_display')
                                    ->label('')
                                    ->html()
                                    ->formatStateUsing(function ($record) {
                                        $imageUrl = $record->display_url;
                                        
                                        if (!$imageUrl) {
                                            // Barcha maydonlarni tekshirish
                                            $debug = [];
                                            if ($record->s3_url) $debug[] = 's3_url: ' . $record->s3_url;
                                            if ($record->s3_path) $debug[] = 's3_path: ' . $record->s3_path;
                                            if ($record->local_path) $debug[] = 'local_path: ' . $record->local_path;
                                            if ($record->image_url) $debug[] = 'image_url: ' . $record->image_url;
                                            
                                            return '<p style="color: red;">Rasm topilmadi</p>' . 
                                                   (!empty($debug) ? '<p style="font-size: 12px; color: gray;">' . implode('<br>', $debug) . '</p>' : '');
                                        }
                                        
                                        // URL'ni tekshirish
                                        $urlInfo = parse_url($imageUrl);
                                        if (!$urlInfo || !isset($urlInfo['scheme'])) {
                                            return '<p style="color: orange;">Noto\'g\'ri URL: ' . htmlspecialchars($imageUrl) . '</p>';
                                        }
                                        
                                        return sprintf(
                                            '<div style="text-align: center;"><img src="%s" alt="Rasm" style="max-width: 100%%; max-height: 400px; object-fit: contain; border: 1px solid #ddd; border-radius: 8px; padding: 8px; background: #f9f9f9;" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';" /><p style="display:none; color: red; padding: 10px;">Rasm yuklanmadi. URL: <a href=\'%s\' target=\'_blank\'>%s</a></p></div>',
                                            htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8'),
                                            htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8'),
                                            htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8')
                                        );
                                    }),
                                Infolists\Components\TextEntry::make('url_info')
                                    ->label('URL (tekshirish uchun)')
                                    ->formatStateUsing(function ($record) {
                                        $url = $record->display_url;
                                        if (!$url) {
                                            return '<span style="color: red;">URL topilmadi</span>';
                                        }
                                        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" style="word-break: break-all; color: #3b82f6; text-decoration: underline;">' . htmlspecialchars($url) . '</a>';
                                    })
                                    ->html()
                                    ->columnSpanFull(),
                            ])
                            ->columns(1)
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
