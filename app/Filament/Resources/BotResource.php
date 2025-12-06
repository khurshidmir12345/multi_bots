<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BotResource\Pages;
use App\Filament\Resources\BotResource\RelationManagers;
use App\Models\Bot;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Http;

class BotResource extends Resource
{
    protected static ?string $model = Bot::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    
    protected static ?string $navigationLabel = 'Botlar';
    
    protected static ?string $modelLabel = 'Bot';
    
    protected static ?string $pluralModelLabel = 'Botlar';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('token')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('webhook_url')
                    ->maxLength(255)
                    ->default(null),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nomi')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
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
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Yangilangan')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Holat')
                    ->placeholder('Barchasi')
                    ->trueLabel('Faol')
                    ->falseLabel('Nofaol'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('setWebhook')
                    ->label('Webhook O\'rnatish')
                    ->icon('heroicon-o-link')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Webhook O\'rnatish')
                    ->modalDescription('Bu bot uchun Telegram webhook o\'rnatiladi. Davom etasizmi?')
                    ->action(function (Bot $record) {
                        try {
                            $url = $record->webhook_url;
                            
                            if (!$url) {
                                Notification::make()
                                    ->title('Xatolik')
                                    ->body('Webhook URL topilmadi. Iltimos, avval bot ma\'lumotlarini saqlang.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            // Telegram API'ga webhook o'rnatish
                            $response = Http::post("https://api.telegram.org/bot{$record->token}/setWebhook", [
                                'url' => $url,
                            ]);

                            $result = $response->json();

                            if ($result['ok'] ?? false) {
                                Notification::make()
                                    ->title('Muvaffaqiyatli')
                                    ->body('Webhook muvaffaqiyatli o\'rnatildi!')
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Xatolik')
                                    ->body($result['description'] ?? 'Webhook o\'rnatilmadi')
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Xatolik')
                                ->body('Xatolik yuz berdi: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (Bot $record) => $record->is_active && $record->webhook_url),
                Tables\Actions\Action::make('deleteWebhook')
                    ->label('Webhook O\'chirish')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Webhook O\'chirish')
                    ->modalDescription('Bu bot uchun Telegram webhook o\'chiriladi. Davom etasizmi?')
                    ->action(function (Bot $record) {
                        try {
                            // Telegram API'dan webhook o'chirish
                            $response = Http::post("https://api.telegram.org/bot{$record->token}/deleteWebhook");

                            $result = $response->json();

                            if ($result['ok'] ?? false) {
                                Notification::make()
                                    ->title('Muvaffaqiyatli')
                                    ->body('Webhook muvaffaqiyatli o\'chirildi!')
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Xatolik')
                                    ->body($result['description'] ?? 'Webhook o\'chirilmadi')
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Xatolik')
                                ->body('Xatolik yuz berdi: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (Bot $record) => $record->is_active),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListBots::route('/'),
            'create' => Pages\CreateBot::route('/create'),
            'view' => Pages\ViewBot::route('/{record}'),
            'edit' => Pages\EditBot::route('/{record}/edit'),
        ];
    }
}
