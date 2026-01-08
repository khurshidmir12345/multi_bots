<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ElonResource\Pages;
use App\Models\Elon;
use App\Models\ElonUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

class ElonResource extends Resource
{
    protected static ?string $model = Elon::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    
    protected static ?string $navigationLabel = 'Elonlar';
    
    protected static ?string $modelLabel = 'Elon';
    
    protected static ?string $pluralModelLabel = 'Elonlar';
    
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Foydalanuvchi')
                    ->schema([
                        Forms\Components\Select::make('elon_user_id')
                            ->label('Foydalanuvchi')
                            ->relationship('elonUser', 'name')
                            ->searchable(['name', 'user_name', 'chat_id'])
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('chat_id')
                                    ->label('Chat ID')
                                    ->required()
                                    ->numeric(),
                                Forms\Components\TextInput::make('name')
                                    ->label('Ism')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('user_name')
                                    ->label('Username')
                                    ->maxLength(255)
                                    ->prefix('@'),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                return ElonUser::create($data)->id;
                            }),
                    ])
                    ->columns(1),
                
                Forms\Components\Section::make('Mashina ma\'lumotlari')
                    ->schema([
                        Forms\Components\TextInput::make('modeli')
                            ->label('Model')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('pozitsiyasi')
                            ->label('Pozitsiya')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('rangi')
                            ->label('Rang')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('kraskasi')
                            ->label('Kraskasi')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('yili')
                            ->label('Yil')
                            ->numeric()
                            ->minValue(1900)
                            ->maxValue(now()->year + 1),
                        Forms\Components\TextInput::make('yurgani')
                            ->label('Yurgani (km)')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('yoqilgisi')
                            ->label('Yoqilg\'i')
                            ->maxLength(255),
                    ])
                    ->columns(2),
                
                Forms\Components\Section::make('Narx va aloqa')
                    ->schema([
                        Forms\Components\TextInput::make('narxi')
                            ->label('Narx')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01),
                        Forms\Components\Select::make('currency')
                            ->label('Valyuta')
                            ->options([
                                'so\'m' => 'So\'m',
                                'dollar' => 'Dollar',
                            ])
                            ->default('so\'m')
                            ->required(),
                        Forms\Components\TextInput::make('tel_1')
                            ->label('Telefon 1')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('tel_2')
                            ->label('Telefon 2')
                            ->tel()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('manzil')
                            ->label('Manzil')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                
                Forms\Components\Section::make('Holat')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                Elon::STATUS_ENDED => 'Tugatilgan',
                                Elon::STATUS_ACCEPTED_USER => 'Foydalanuvchi tomonidan qabul qilingan',
                                Elon::STATUS_SENDED_TO_ADMIN => 'Admin\'ga yuborilgan',
                                Elon::STATUS_ACCEPTED_ADMIN => 'Admin tomonidan qabul qilingan',
                                Elon::STATUS_COMPLATED => 'Kanalga chiqarilgan',
                            ])
                            ->default(Elon::STATUS_ACCEPTED_USER)
                            ->required(),
                        Forms\Components\Toggle::make('cancelled_from_admin')
                            ->label('Admin tomonidan bekor qilingan')
                            ->default(false),
                        Forms\Components\Toggle::make('cancelled_from_user')
                            ->label('Foydalanuvchi tomonidan bekor qilingan')
                            ->default(false),
                        Forms\Components\Toggle::make('is_sold')
                            ->label('Sotilgan')
                            ->default(false),
                        Forms\Components\Textarea::make('sold_feedback')
                            ->label('Sotilgan feedback')
                            ->rows(3)
                            ->columnSpanFull()
                            ->visible(fn ($get) => $get('is_sold')),
                        Forms\Components\TextInput::make('elon_message_id')
                            ->label('Kanal Message ID')
                            ->maxLength(255),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('elonUser.name')
                    ->label('Foydalanuvchi')
                    ->searchable(['elonUser.name', 'elonUser.user_name', 'elonUser.chat_id'])
                    ->sortable()
                    ->formatStateUsing(function ($record) {
                        $user = $record->elonUser;
                        if (!$user) return '-';
                        $name = $user->name ?? '-';
                        if ($user->user_name) {
                            $name .= ' (@' . $user->user_name . ')';
                        }
                        return $name;
                    }),
                
                Tables\Columns\TextColumn::make('modeli')
                    ->label('Model')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('pozitsiyasi')
                    ->label('Pozitsiya')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('rangi')
                    ->label('Rang')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('kraskasi')
                    ->label('Kraskasi')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('yili')
                    ->label('Yil')
                    ->sortable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('yurgani')
                    ->label('Yurgani (km)')
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        return $state ? number_format($state, 0, '.', ' ') . ' km' : '-';
                    })
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('yoqilgisi')
                    ->label('Yoqilg\'i')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('narxi')
                    ->label('Narx')
                    ->sortable()
                    ->formatStateUsing(function ($state, $record) {
                        if (!$state) return '-';
                        $currency = $record->currency === 'dollar' ? '$' : '';
                        $currencyText = $record->currency === 'dollar' ? ' dollar' : ' so\'m';
                        return $currency . number_format($state, 0, '.', ' ') . $currencyText;
                    })
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('tel_1')
                    ->label('Tel 1')
                    ->searchable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('tel_2')
                    ->label('Tel 2')
                    ->searchable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('manzil')
                    ->label('Manzil')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(function ($record) {
                        return $record->manzil;
                    })
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('images_count')
                    ->label('Rasmlar')
                    ->counts('images')
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Elon::STATUS_ENDED => 'danger',
                        Elon::STATUS_ACCEPTED_USER => 'info',
                        Elon::STATUS_SENDED_TO_ADMIN => 'warning',
                        Elon::STATUS_ACCEPTED_ADMIN => 'success',
                        Elon::STATUS_COMPLATED => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Elon::STATUS_ENDED => 'Tugatilgan',
                        Elon::STATUS_ACCEPTED_USER => 'Foydalanuvchi qabul qildi',
                        Elon::STATUS_SENDED_TO_ADMIN => 'Admin\'ga yuborilgan',
                        Elon::STATUS_ACCEPTED_ADMIN => 'Admin qabul qildi',
                        Elon::STATUS_COMPLATED => 'Kanalga chiqarilgan',
                        default => $state,
                    })
                    ->sortable()
                    ->searchable(),
                
                Tables\Columns\IconColumn::make('cancelled_from_admin')
                    ->label('Admin bekor')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->toggleable(),
                
                Tables\Columns\IconColumn::make('cancelled_from_user')
                    ->label('User bekor')
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->toggleable(),
                
                Tables\Columns\IconColumn::make('is_sold')
                    ->label('Sotilgan')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->toggleable(),
                
                Tables\Columns\TextColumn::make('sold_feedback')
                    ->label('Sotilgan feedback')
                    ->limit(30)
                    ->tooltip(function ($record) {
                        return $record->sold_feedback;
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('elon_message_id')
                    ->label('Kanal Message ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Yaratilgan')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Yangilangan')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                
                Tables\Columns\TextColumn::make('deleted_at')
                    ->label('O\'chirilgan')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        Elon::STATUS_ENDED => 'Tugatilgan',
                        Elon::STATUS_ACCEPTED_USER => 'Foydalanuvchi qabul qildi',
                        Elon::STATUS_SENDED_TO_ADMIN => 'Admin\'ga yuborilgan',
                        Elon::STATUS_ACCEPTED_ADMIN => 'Admin qabul qildi',
                        Elon::STATUS_COMPLATED => 'Kanalga chiqarilgan',
                    ])
                    ->multiple(),
                
                Tables\Filters\TernaryFilter::make('cancelled_from_admin')
                    ->label('Admin tomonidan bekor qilingan')
                    ->placeholder('Barchasi')
                    ->trueLabel('Ha')
                    ->falseLabel('Yo\'q'),
                
                Tables\Filters\TernaryFilter::make('cancelled_from_user')
                    ->label('Foydalanuvchi tomonidan bekor qilingan')
                    ->placeholder('Barchasi')
                    ->trueLabel('Ha')
                    ->falseLabel('Yo\'q'),
                
                Tables\Filters\TernaryFilter::make('is_sold')
                    ->label('Sotilgan')
                    ->placeholder('Barchasi')
                    ->trueLabel('Ha')
                    ->falseLabel('Yo\'q'),
                
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('Yaratilgan (dan)'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Yaratilgan (gacha)'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListElons::route('/'),
            'create' => Pages\CreateElon::route('/create'),
            'view' => Pages\ViewElon::route('/{record}'),
            'edit' => Pages\EditElon::route('/{record}/edit'),
        ];
    }
}
