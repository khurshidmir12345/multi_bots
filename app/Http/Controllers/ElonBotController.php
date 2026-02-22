<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use App\Services\ElonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ElonBotController extends Controller
{
    /**
     * Telegram webhook'ni qabul qilish (Elon Bot uchun)
     */
    public function handle(Request $request, string $slug)
    {
        $bot = Bot::bySlug($slug)->active()->first();
        
        if (!$bot) {
            return response()->json(['error' => 'Bot not found'], 404);
        }

        try {
            $updateData = $request->all();
            
            $update = empty($updateData) 
                ? (new Api($bot->token))->getWebhookUpdate() 
                : new \Telegram\Bot\Objects\Update($updateData);

            $elonService = new ElonService($bot);
            $elonService->handleUpdate($update);

            return response()->json(['ok' => true], 200);
        } catch (\Exception $e) {
            Log::error("Elon Bot webhook error", [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'line' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return response()->json(['ok' => false], 500);
        }
    }
}
