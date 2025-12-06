<?php

namespace App\Filament\Resources\TelegramGroupResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';
    
    protected static ?string $title = 'Guruh a\'zolari';
    
    protected static ?string $modelLabel = 'A\'zo';
    
    protected static ?string $pluralModelLabel = 'A\'zolar';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                Tables\Columns\TextColumn::make('telegram_user_id')
                    ->label('Telegram ID')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('To\'liq ism')
                    ->getStateUsing(fn ($record): string => $record->full_name)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->formatStateUsing(fn (?string $state): string => $state ? '@' . $state : '-')
                    ->icon('heroicon-o-at-symbol'),
                Tables\Columns\TextColumn::make('pivot.joined_at')
                    ->label('Qo\'shilgan')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.left_at')
                    ->label('Ketgan')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('-')
                    ->color(fn ($state) => $state ? 'danger' : 'success'),
                Tables\Columns\IconColumn::make('is_bot')
                    ->label('Bot')
                    ->boolean()
                    ->trueIcon('heroicon-o-robot')
                    ->falseIcon('heroicon-o-user'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('pivot.left_at')
                    ->label('Holat')
                    ->placeholder('Barchasi')
                    ->trueLabel('Ketgan')
                    ->falseLabel('Faol')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('group_user.left_at'),
                        false: fn (Builder $query) => $query->whereNull('group_user.left_at'),
                    ),
            ])
            ->headerActions([
                // Userlar avtomatik qo'shiladi, qo'lda qo'shish kerak emas
            ])
            ->actions([
                // Faqat ko'rish, tahrirlash kerak emas
            ])
            ->defaultSort('pivot.joined_at', 'desc')
            ->emptyStateHeading('A\'zolar yo\'q')
            ->emptyStateDescription('Bu guruhda hozircha a\'zolar yo\'q');
    }
}
