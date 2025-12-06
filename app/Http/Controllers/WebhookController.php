<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Telegram webhook'ni qabul qilish
     */
    public function handle(Request $request, string $slug)
    {
        // Bot'ni topish
        $bot = Bot::bySlug($slug)->active()->first();
        
        if (!$bot) {
            return response()->json(['error' => 'Bot not found'], 404);
        }

        // Bu yerda Telegram'dan kelgan ma'lumotlarni qayta ishlash
        $data = $request->all();
        
        // Log yozish (keyinchalik to'liq ishlatiladi)
        Log::info("Webhook received for bot: {$slug}", $data);

        // Hozircha faqat 200 qaytaramiz
        // Keyinchalik bu yerda bot logikasini yozasiz
        return response()->json(['ok' => true], 200);
    }
}
