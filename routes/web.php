<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Telegram webhook route (CSRF'dan chiqarilgan)
Route::post('/bot/{slug}/webhook', [WebhookController::class, 'handle'])
    ->middleware('web')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);


Route::get('test', function () {
    return response()->json(['ok' => true], 200);
});