<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Services\ElonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class ElonBotController extends Controller
{
    /**
     * Telegram webhook'ni qabul qilish (Elon Bot uchun)
     */
    public function handle(Request $request, string $slug)
    {
        Log::info("ElonBotController: handle called", [
            'slug' => $slug,
            'request_data' => $request->all(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
        ]);

        // Bot'ni topish
        $bot = Bot::bySlug($slug)->active()->first();
        
        if (!$bot) {
            Log::warning("Elon Bot not found", [
                'slug' => $slug,
                'all_bots' => Bot::pluck('slug')->toArray(),
            ]);
            return response()->json(['error' => 'Bot not found', 'slug' => $slug], 404);
        }

        Log::info("Elon Bot found", [
            'bot_id' => $bot->id,
            'bot_slug' => $bot->slug,
            'bot_type' => $bot->type,
            'webhook_url' => $bot->webhook_url,
        ]);

        try {
            $telegram = new Api($bot->token);
            $updateData = $request->all();
            
            Log::info("Elon Bot webhook received - raw data", [
                'bot_id' => $bot->id,
                'slug' => $slug,
                'update_data' => $updateData,
            ]);
            
            $update = empty($updateData) 
                ? $telegram->getWebhookUpdate() 
                : new \Telegram\Bot\Objects\Update($updateData);

            Log::info("Elon Bot webhook - update created", [
                'bot_id' => $bot->id,
                'update_id' => $update->updateId ?? null,
                'has_message' => $update->message !== null,
                'has_callback_query' => $update->callbackQuery !== null,
            ]);

            // ElonService orqali update'ni handle qilish
            $elonService = new ElonService($bot);
            $elonService->handleUpdate($update);
            
            Log::info("Elon Bot webhook processed successfully", [
                'bot_id' => $bot->id,
                'slug' => $slug,
                'update_id' => $update->updateId ?? null,
            ]);

            return response()->json(['ok' => true], 200);
        } catch (\Exception $e) {
            Log::error("Error handling Elon Bot webhook", [
                'bot_id' => $bot->id,
                'slug' => $slug,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
