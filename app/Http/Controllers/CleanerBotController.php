<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Services\TelegramEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class CleanerBotController extends Controller
{
    public function __construct(
        private TelegramEventService $eventService
    ) {
    }

    /**
     * Telegram webhook'ni qabul qilish
     */
    public function handle(Request $request, string $slug)
    {
        // Bot'ni topish
        $bot = Bot::bySlug($slug)->active()->first();
        
        if (!$bot) {
            Log::warning("Bot not found", ['slug' => $slug]);
            return response()->json(['error' => 'Bot not found'], 404);
        }

        try {
            $telegram = new Api($bot->token);
            $updateData = $request->all();
            
            $update = empty($updateData) 
                ? $telegram->getWebhookUpdate() 
                : new \Telegram\Bot\Objects\Update($updateData);

            $this->eventService->handleUpdate($bot, $update);

            return response()->json(['ok' => true], 200);
        } catch (\Exception $e) {
            Log::error("Error handling webhook", [
                'bot_id' => $bot->id,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false], 500);
        }
    }
}
