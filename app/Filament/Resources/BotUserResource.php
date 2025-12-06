<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BotUserResource\Pages;
use App\Filament\Resources\BotUserResource\RelationManagers;
use App\Models\BotUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BotUserResource extends Resource
{
    protected static ?string $model = BotUser::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    
    protected static ?string $navigationLabel = 'Foydalanuvchilar';
    
    protected static ?string $modelLabel = 'Foydalanuvchi';
    
    protected static ?string $pluralModelLabel = 'Foydalanuvchilar';
    
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Asosiy ma\'lumotlar')
                    ->schema([
                        Forms\Components\TextInput::make('telegram_user_id')
                            ->label('Telegram User ID')
                            ->required()
                            ->numeric()
                            ->disabled()
                            ->helperText('Telegram foydalanuvchi ID si'),
                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->maxLength(255)
                            ->prefix('@')
                            ->disabled(),
                        Forms\Components\TextInput::make('first_name')
                            ->label('Ism')
                            ->required()
                            ->maxLength(255)
                            ->disabled(),
                        Forms\Components\TextInput::make('last_name')
                            ->label('Familiya')
                            ->maxLength(255)
                            ->default(null)
                            ->disabled(),
                    ])->columns(2),
                Forms\Components\Section::make('Holat')
                    ->schema([
                        Forms\Components\Toggle::make('is_bot')
                            ->label('Bot')
                            ->helperText('Bu foydalanuvchi botmi?')
                            ->disabled(),
                        Forms\Components\Select::make('status')
                            ->label('Holat')
                            ->options([
                                'active' => 'Faol',
                                'banned' => 'Bloklangan',
                                'left' => 'Ketgan',
                            ])
                            ->required()
                            ->default('active'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('telegram_user_id')
                    ->label('Telegram ID')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('To\'liq ism')
                    ->getStateUsing(fn (BotUser $record): string => $record->full_name)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->formatStateUsing(fn (?string $state): string => $state ? '@' . $state : '-')
                    ->icon('heroicon-o-at-symbol'),
                Tables\Columns\IconColumn::make('is_bot')
                    ->label('Bot')
                    ->boolean()
                    ->trueIcon('heroicon-o-robot')
                    ->falseIcon('heroicon-o-user'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Holat')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'banned' => 'danger',
                        'left' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        'active' => 'Faol',
                        'banned' => 'Bloklangan',
                        'left' => 'Ketgan',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Yaratilgan')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_bot')
                    ->label('Bot')
                    ->placeholder('Barchasi')
                    ->trueLabel('Bot')
                    ->falseLabel('Foydalanuvchi'),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Holat')
                    ->options([
                        'active' => 'Faol',
                        'banned' => 'Bloklangan',
                        'left' => 'Ketgan',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBotUsers::route('/'),
            'edit' => Pages\EditBotUser::route('/{record}/edit'),
        ];
    }
}
