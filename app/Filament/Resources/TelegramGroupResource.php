<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TelegramGroupResource\Pages;
use App\Filament\Resources\TelegramGroupResource\RelationManagers;
use App\Models\TelegramGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TelegramGroupResource extends Resource
{
    protected static ?string $model = TelegramGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    
    protected static ?string $navigationLabel = 'Telegram Guruhlar';
    
    protected static ?string $modelLabel = 'Guruh';
    
    protected static ?string $pluralModelLabel = 'Guruhlar';
    
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Asosiy ma\'lumotlar')
                    ->schema([
                        Forms\Components\Select::make('bot_id')
                            ->label('Bot')
                            ->relationship('bot', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('telegram_group_id')
                            ->label('Telegram Group ID')
                            ->required()
                            ->numeric()
                            ->disabled()
                            ->helperText('Telegram guruh ID si'),
                        Forms\Components\TextInput::make('title')
                            ->label('Guruh nomi')
                            ->maxLength(255)
                            ->disabled(),
                        Forms\Components\Select::make('type')
                            ->label('Guruh turi')
                            ->options([
                                'group' => 'Group',
                                'supergroup' => 'Supergroup',
                            ])
                            ->required()
                            ->disabled(),
                    ])->columns(2),
                Forms\Components\Section::make('Holat')
                    ->schema([
                        Forms\Components\Toggle::make('status')
                            ->label('Faol')
                            ->helperText('Guruh faolmi yoki yo\'qmi')
                            ->default(true),
                        Forms\Components\TextInput::make('chat_members_count')
                            ->label('A\'zolar soni')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->helperText('Guruhdagi a\'zolar soni'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('bot.name')
                    ->label('Bot')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Guruh nomi')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('type')
                    ->label('Turi')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'supergroup' => 'success',
                        default => 'primary',
                    })
                    ->formatStateUsing(fn (string $state): string => $state === 'supergroup' ? 'Supergroup' : 'Group'),
                Tables\Columns\TextColumn::make('chat_members_count')
                    ->label('A\'zolar')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('success'),
                Tables\Columns\IconColumn::make('status')
                    ->label('Holat')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Yaratilgan')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bot_id')
                    ->label('Bot')
                    ->relationship('bot', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Guruh turi')
                    ->options([
                        'group' => 'Group',
                        'supergroup' => 'Supergroup',
                    ]),
                Tables\Filters\TernaryFilter::make('status')
                    ->label('Holat')
                    ->placeholder('Barchasi')
                    ->trueLabel('Faol')
                    ->falseLabel('Nofaol'),
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
            RelationManagers\UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramGroups::route('/'),
            'edit' => Pages\EditTelegramGroup::route('/{record}/edit'),
        ];
    }
}
